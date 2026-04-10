<script>
(function () {
  if (typeof window.POADO_EMA === 'undefined' || typeof Chart === 'undefined') {
    console.error('[EMA 10Y] Required chart libraries are missing');
    return;
  }

  const canvas = document.getElementById('ema10yChart');
  if (!canvas) {
    console.error('[EMA 10Y] Canvas #ema10yChart not found');
    return;
  }

  const series = window.POADO_EMA.series10YearsMonthly();
  const milestones = window.POADO_EMA.milestones();

  const labels = series.map(p => p.x);
  const values = series.map(p => p.y);

  const milestoneMap = new Map([
    [0, Number(milestones.start.price)],
    [365, Number(milestones.year1.price)],
    [3650, Number(milestones.year10.price)]
  ]);

  const milestonePoints = series.map(p => {
    return milestoneMap.has(p.day) ? milestoneMap.get(p.day) : null;
  });

  new Chart(canvas, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [
        {
          label: 'EMA$',
          data: values,
          borderWidth: 2,
          pointRadius: 0,
          tension: 0.25,
          fill: true
        },
        {
          label: 'Milestones',
          data: milestonePoints,
          showLine: false,
          pointRadius: 4,
          pointHoverRadius: 5,
          borderWidth: 0
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: {
        mode: 'index',
        intersect: false
      },
      plugins: {
        legend: {
          display: false
        },
        tooltip: {
          callbacks: {
            title(items) {
              return items && items[0] ? items[0].label : '';
            },
            label(ctx) {
              if (ctx.datasetIndex === 0) {
                return 'EMA$ ' + Number(ctx.parsed.y).toFixed(6);
              }

              const idx = ctx.dataIndex;
              const point = series[idx];
              if (!point) return 'Milestone';

              if (point.day === 0) return 'Start 0.100000';
              if (point.day === 365) return 'Year 1 0.106000';
              if (point.day === 3650) return 'Year 10 100.000000';
              return 'EMA$ ' + Number(ctx.parsed.y).toFixed(6);
            }
          }
        }
      },
      scales: {
        x: {
          grid: {
            display: false
          },
          ticks: {
            maxTicksLimit: 8
          }
        },
        y: {
          beginAtZero: false,
          ticks: {
            callback(value) {
              return Number(value).toFixed(6);
            }
          }
        }
      }
    }
  });
})();
</script>