import React, { useState } from 'react';
import { api } from '../../api';
import { isApiError } from '../../api/errors';
import { useMe, useSession } from '../../auth/SessionProvider';
import { Banner, Body, Button, Gap, Screen, TextField } from '../../ui';

/**
 * Phone verification by texted code. Both endpoints are throttled hard —
 * each text costs money — and a 503 sms_unavailable means the operator has
 * not set up an SMS gateway; that is shown, not retried.
 */
export default function PhoneScreen() {
  const me = useMe();
  const { refreshMe } = useSession();
  const [phone, setPhone] = useState(me.phone ?? '');
  const [code, setCode] = useState('');
  const [sent, setSent] = useState(false);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'success' | 'warning' | 'danger' | 'info'; text: string } | null>(null);
  const [fieldError, setFieldError] = useState<string | null>(null);

  async function sendCode() {
    setBusy(true);
    setNotice(null);
    setFieldError(null);
    try {
      const result = await api.sendPhoneCode(phone.trim());
      setSent(true);
      setNotice({ tone: 'info', text: `Code sent. It expires at ${new Date(result.data.expires_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}.` });
    } catch (e) {
      if (isApiError(e) && e.code === 'sms_unavailable') {
        setNotice({ tone: 'warning', text: 'Phone verification is not available right now.' });
      } else if (isApiError(e) && e.code === 'validation_failed') {
        setFieldError(e.fieldError('phone') ?? e.firstError);
      } else {
        setNotice({ tone: 'danger', text: isApiError(e) ? e.message : 'Could not send the code.' });
      }
    } finally {
      setBusy(false);
    }
  }

  async function verify() {
    setBusy(true);
    setNotice(null);
    try {
      await api.verifyPhone(code.trim());
      await refreshMe();
      setSent(false);
      setCode('');
      setNotice({ tone: 'success', text: 'Phone number verified.' });
    } catch (e) {
      setNotice({ tone: isApiError(e) && e.code === 'validation_failed' ? 'warning' : 'danger', text: isApiError(e) ? (e.fieldError('code') ?? e.firstError ?? e.message) : 'Could not verify.' });
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen scroll>
      {notice ? <Banner tone={notice.tone}>{notice.text}</Banner> : null}
      <Body muted>{me.phone ? `Current number: ${me.phone}` : 'Add a phone number to verify it.'}</Body>
      <Gap />
      <TextField label="Phone number" value={phone} onChangeText={setPhone} error={fieldError} keyboardType="phone-pad" autoComplete="tel" placeholder="+44 7700 900000" editable={!sent} />
      {!sent ? (
        <Button title="Text me a code" onPress={sendCode} loading={busy} disabled={phone.trim().length < 8} />
      ) : (
        <>
          <TextField label="6-digit code" value={code} onChangeText={setCode} keyboardType="number-pad" maxLength={6} autoComplete="one-time-code" textContentType="oneTimeCode" />
          <Button title="Verify" onPress={verify} loading={busy} disabled={code.trim().length !== 6} />
          <Gap size="sm" />
          <Button title="Use a different number" variant="ghost" onPress={() => setSent(false)} />
        </>
      )}
    </Screen>
  );
}
