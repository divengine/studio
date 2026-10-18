/**
 * @file
 * Opens and closes the sidebar on narrow screens.
 */
((Drupal, once) => {
  Drupal.behaviors.divengineStudioSidebar = {
    attach(context) {
      once('dv-sidebar-toggle', '[data-dv-sidebar-toggle]', context).forEach((button) => {
        const app = button.closest('[data-dv-app]');
        button.addEventListener('click', () => {
          const open = app.classList.toggle('is-sidebar-open');
          button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
      });
    },
  };
})(Drupal, once);
