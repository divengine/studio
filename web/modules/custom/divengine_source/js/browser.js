/**
 * @file
 * The code viewer: highlighting and line numbers you can link to (#L42).
 */
((Drupal, once, hljs) => {
  /**
   * Splits highlighted HTML into lines, closing the spans still open at the
   * end of a line and opening them again on the next one, so a token that
   * spans lines (a block comment, a string) stays highlighted.
   */
  const splitLines = (html) => {
    const lines = [];
    const open = [];
    let current = '';
    const pattern = /(<span[^>]*>)|(<\/span>)|(\n)|([^<\n]+|<)/g;
    let match = pattern.exec(html);
    while (match) {
      if (match[1]) {
        open.push(match[1]);
        current += match[1];
      }
      else if (match[2]) {
        open.pop();
        current += match[2];
      }
      else if (match[3]) {
        lines.push(current + '</span>'.repeat(open.length));
        current = open.join('');
      }
      else {
        current += match[4];
      }
      match = pattern.exec(html);
    }
    lines.push(current);
    return lines;
  };

  Drupal.behaviors.divengineSourceViewer = {
    attach(context) {
      once('dv-code', '[data-dv-code] > code', context).forEach((code) => {
        // Very large files stay plain: highlighting them would freeze the page.
        if (code.textContent.length < 400000 && hljs) {
          hljs.highlightElement(code);
        }
        const lines = splitLines(code.innerHTML);
        if (lines.length && lines[lines.length - 1] === '') {
          lines.pop();
        }
        code.innerHTML = lines
          .map((line, index) => `<span class="dv-line" id="L${index + 1}"><a class="dv-line__number" href="#L${index + 1}" aria-hidden="true">${index + 1}</a><span class="dv-line__text">${line || ' '}</span></span>`)
          .join('');
        if (window.location.hash && document.getElementById(window.location.hash.slice(1))) {
          const target = document.getElementById(window.location.hash.slice(1));
          target.classList.add('is-target');
          target.scrollIntoView({ block: 'center' });
        }
      });
    },
  };
})(Drupal, once, window.hljs);
