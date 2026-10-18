/**
 * @file
 * The "Print or save as PDF" button of QA reports.
 */
((Drupal, once) => {
  Drupal.behaviors.divengineQaReport = {
    attach(context) {
      once('dv-print', '[data-dv-print]', context).forEach((button) => {
        button.addEventListener('click', () => window.print());
      });
    },
  };
})(Drupal, once);
