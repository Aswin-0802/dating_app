/** Whole years between an ISO date (YYYY-MM-DD) and today, or null if unparseable. */
export function ageFromBirthdate(birthdate: string, today: Date = new Date()): number | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(birthdate.trim());
  if (!match) return null;

  const [, y, m, d] = match.map(Number);
  const born = new Date(Date.UTC(y, m - 1, d));
  if (Number.isNaN(born.getTime()) || born.getUTCMonth() !== m - 1) return null;

  let age = today.getUTCFullYear() - y;
  const beforeBirthday = today.getUTCMonth() < m - 1 || (today.getUTCMonth() === m - 1 && today.getUTCDate() < d);
  if (beforeBirthday) age--;

  return age;
}
