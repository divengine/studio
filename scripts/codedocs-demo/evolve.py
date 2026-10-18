#!/usr/bin/env python3
"""Add a second, documented version of each demo. Run refresh.sh afterwards."""
import os
from pathlib import Path
import subprocess

root = Path(os.environ.get('CODEDOCS_DEMO_ROOT', str(Path.home() / 'workspace/codedocs-demo')))
changes = {
    'php-shop': ('src/Product.php', '''
  /** Apply a percentage discount without changing the catalog price.
   * @param int $percent Discount from zero to one hundred.
   * @return int Discounted unit price in cents.
   */
  public function discountedPrice(int $percent): int {
    if ($percent < 0 || $percent > 100) { throw new \\InvalidArgumentException('Invalid discount.'); }
    return intdiv($this->priceCents * (100 - $percent), 100);
  }
'''),
    'ts-shop': ('src/Cart.ts', '''
/** Apply the checkout promotion to an amount in cents.
 * @param totalCents Basket total before the promotion.
 * @param percent Percentage between zero and one hundred.
 * @returns Discounted basket amount.
 */
export function discountedTotal(totalCents: number, percent: number): number {
  if (percent < 0 || percent > 100) throw new Error('Invalid discount');
  return Math.floor(totalCents * (100 - percent) / 100);
}
'''),
}
for folder, (relative, addition) in changes.items():
    project = root / folder
    if subprocess.check_output(['git', '-C', str(project), 'status', '--porcelain'], text=True).strip():
        raise SystemExit(f'Commit your changes in {project} first.')
    file = project / relative
    source = file.read_text()
    marker = 'discountedPrice' if folder == 'php-shop' else 'discountedTotal'
    if marker in source:
        continue
    source = source.rstrip()[:-1] + addition + '}\n' if folder == 'php-shop' else source + addition
    file.write_text(source)
    subprocess.run(['git', '-C', str(project), 'add', relative], check=True)
    subprocess.run(['git', '-C', str(project), 'commit', '-m', 'Document checkout promotion support'], check=True)
