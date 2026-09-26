/** True when `current` is older than `minimum`. Compares dotted numerics; anything unparseable counts as 0. */
export function isOlderThan(current: string, minimum: string): boolean {
  const a = parse(current);
  const b = parse(minimum);
  const length = Math.max(a.length, b.length);

  for (let i = 0; i < length; i++) {
    const x = a[i] ?? 0;
    const y = b[i] ?? 0;
    if (x < y) return true;
    if (x > y) return false;
  }

  return false;
}

function parse(version: string): number[] {
  return version
    .split('-')[0]
    .split('.')
    .map((part) => Number.parseInt(part, 10) || 0);
}
