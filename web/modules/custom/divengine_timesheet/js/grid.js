/**
 * @file
 * Live totals of the weekly timesheet grid while typing.
 */
((Drupal, once) => {
  const parse = (value) => {
    const text = value.trim().replace(',', '.');
    if (!text) {
      return 0;
    }
    const time = text.match(/^(\d+):([0-5]?\d)$/);
    if (time) {
      return Number(time[1]) + Number(time[2]) / 60;
    }
    const number = Number(text);
    return Number.isFinite(number) ? number : 0;
  };
  const format = (hours) => {
    const minutes = Math.round(hours * 60);
    return `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}`;
  };

  Drupal.behaviors.divengineTimesheetGrid = {
    attach(context) {
      once('dv-grid', 'table.dv-grid', context).forEach((table) => {
        const refresh = () => {
          const days = {};
          let week = 0;
          table.querySelectorAll('tbody tr').forEach((row) => {
            let total = 0;
            row.querySelectorAll('.dv-grid__cell').forEach((cell) => {
              const hours = parse(cell.value);
              total += hours;
              days[cell.dataset.dvDay] = (days[cell.dataset.dvDay] || 0) + hours;
            });
            const rowTotal = row.querySelector('.dv-grid__row-total');
            if (rowTotal) {
              rowTotal.textContent = format(total);
              week += total;
            }
          });
          table.querySelectorAll('.dv-grid__day-total').forEach((cell) => {
            cell.textContent = format(days[cell.dataset.dvDay] || 0);
          });
          const weekTotal = table.querySelector('.dv-grid__week-total');
          if (weekTotal) {
            weekTotal.textContent = format(week);
          }
        };
        table.addEventListener('input', refresh);
      });
    },
  };
})(Drupal, once);
