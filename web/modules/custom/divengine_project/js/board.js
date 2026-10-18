/**
 * @file
 * Drag and drop on the project board: moving a card to another column
 * changes the task's stage; the order of the column is saved too.
 */
((Drupal, once, Sortable) => {
  let token = null;

  const csrfToken = async () => {
    if (!token) {
      const response = await fetch(Drupal.url('session/token'));
      token = await response.text();
    }
    return token;
  };

  const refreshCounts = (board) => {
    board.querySelectorAll('.dv-board__column').forEach((column) => {
      const count = column.querySelector('.dv-board__count');
      if (count) {
        count.textContent = column.querySelectorAll('.dv-card').length;
      }
    });
  };

  Drupal.behaviors.divengineProjectBoard = {
    attach(context) {
      once('dv-board', '[data-dv-board]', context).forEach((board) => {
        // Created on the first error only, so no empty message area shows.
        let messages = null;
        const error = (text) => {
          messages = messages || new Drupal.Message();
          messages.add(text, { type: 'error' });
        };
        board.querySelectorAll('.dv-board__cards').forEach((list) => {
          Sortable.create(list, {
            group: 'dv-board',
            animation: 150,
            filter: '[data-dv-draggable="false"]',
            preventOnFilter: false,
            onEnd: async (event) => {
              const card = event.item;
              const target = event.to;
              const order = [...target.querySelectorAll('.dv-card')].map((el) => Number(el.dataset.dvTask));
              try {
                const response = await fetch(board.dataset.dvBoard, {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': await csrfToken() },
                  body: JSON.stringify({ task: Number(card.dataset.dvTask), stage: target.dataset.dvStage, order }),
                });
                const result = await response.json();
                if (!response.ok) {
                  // Put the card back where it was.
                  event.from.insertBefore(card, event.from.children[event.oldIndex] || null);
                  error(result.message || Drupal.t('The task could not be moved.'));
                }
              }
              catch (error) {
                event.from.insertBefore(card, event.from.children[event.oldIndex] || null);
                error(Drupal.t('The task could not be moved. Check your connection and try again.'));
              }
              refreshCounts(board);
            },
          });
        });
      });
    },
  };
})(Drupal, once, Sortable);
