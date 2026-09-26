/**
 * Connectivity smoke test for the API layer, run under Node — no device needed.
 *
 *   npx tsx scripts/apicheck.mts [baseUrl] [email] [password]
 *
 * Exercises the same client, endpoints and error interpreter the app uses,
 * against a real server: config, login, /me, /deck, /conversations, and the
 * error codes the app branches on.
 */
import { ApiClient } from '../src/api/client';
import { makeApi } from '../src/api/endpoints';
import { isApiError } from '../src/api/errors';

const [baseUrl = 'http://127.0.0.1:8000/api/v1', email = 'revathi.49@outlook.com', password = 'password'] = process.argv.slice(2);

let token: string | null = null;
const client = new ApiClient({ baseUrl, getToken: async () => token });
const api = makeApi(client);

const config = await api.config();
console.log('config:', `${config.max_photos} photos, ${config.daily_like_limit} likes/day, min age ${config.min_age}, min version ${config.min_supported_version}`);

const auth = await api.login(email, password);
token = auth.token;
console.log('login:', auth.user.display_name, `(${auth.user.account_status}, ${auth.user.profile_completion}% complete)`);

const me = await api.me();
console.log('/me:', `${me.data.photos?.length ?? 0} photos, premium=${me.data.is_premium}, restrictions=${JSON.stringify(me.data.restrictions)}`);

const deck = await api.deck(3);
console.log('/deck:', `${deck.data.length} people; first: ${deck.data[0]?.display_name}, ${deck.data[0]?.age}, verified=${deck.data[0]?.is_verified}`);

const convs = await api.conversations();
console.log('/conversations:', `${convs.data.length} open; next_cursor ${convs.meta.next_cursor === null ? 'null' : 'present'}`);

async function expectError(label: string, run: () => Promise<unknown>) {
  try {
    await run();
    console.log(`${label} -> UNEXPECTED SUCCESS`);
  } catch (e) {
    if (isApiError(e)) {
      console.log(`${label} -> ${e.status} ${e.code}`, e.count !== null ? `count=${e.count}` : '', e.firstError ?? '');
    } else {
      throw e;
    }
  }
}

await expectError('/me/likers as a free member', () => api.likers());
await expectError('message to a bogus thread', () => api.sendMessage('00000000-0000-0000-0000-000000000000', 'x'));
await expectError('lone age_min', () => api.updatePreferences({ age_min: 60 }));
token = 'not-a-token';
await expectError('bad token', () => api.me());
