(function () {
  'use strict';

  const EMA_MODEL = {
    startUtc: '2026-03-01T00:00:00Z',
    basePrice: 0.10,
    displayDp: 6,
    yearDays: 365,
    totalYears: 10,
    year1Multiplier: 1.06,
    totalTargetMultiplier: 1000
  };

  function emaStartDate() {
    return new Date(EMA_MODEL.startUtc);
  }

  function emaUtcDate(dateLike) {
    if (dateLike instanceof Date) return new Date(dateLike.getTime());
    if (typeof dateLike === 'string' && dateLike.trim() !== '') return new Date(dateLike);
    return new Date();
  }

  function emaDayIndex(dateLike) {
    const start = emaStartDate();
    const now = emaUtcDate(dateLike);
    const ms = now.getTime() - start.getTime();
    if (ms <= 0) return 0;
    return Math.floor(ms / 86400000);
  }

  function emaRemainingDailyFactor() {
    const remainingTarget = EMA_MODEL.totalTargetMultiplier / EMA_MODEL.year1Multiplier;
    const remainingDays = (EMA_MODEL.totalYears - 1) * EMA_MODEL.yearDays;
    return Math.pow(remainingTarget, 1 / remainingDays);
  }

  function emaPriceByDay(dayIndex) {
    const d = Math.max(0, Number(dayIndex) || 0);

    if (d <= EMA_MODEL.yearDays) {
      const ratio = Math.pow(EMA_MODEL.year1Multiplier, d / EMA_MODEL.yearDays);
      return EMA_MODEL.basePrice * ratio;
    }

    const year1Price = EMA_MODEL.basePrice * EMA_MODEL.year1Multiplier;
    const remainingDays = d - EMA_MODEL.yearDays;
    const dailyFactor = emaRemainingDailyFactor();
    return year1Price * Math.pow(dailyFactor, remainingDays);
  }

  function emaPriceByDate(dateLike) {
    return emaPriceByDay(emaDayIndex(dateLike));
  }

  function emaFormatPrice(value, dp) {
    return Number(value || 0).toFixed(typeof dp === 'number' ? dp : EMA_MODEL.displayDp);
  }

  function emaPointForDay(dayIndex) {
    const start = emaStartDate();
    const dt = new Date(start.getTime() + (dayIndex * 86400000));
    const price = emaPriceByDay(dayIndex);

    return {
      day: dayIndex,
      x: dt.toISOString().slice(0, 10),
      y: Number(emaFormatPrice(price, EMA_MODEL.displayDp)),
      price: emaFormatPrice(price, EMA_MODEL.displayDp),
      date_utc: dt.toISOString().slice(0, 19).replace('T', ' ')
    };
  }

  function emaSeriesByDays(totalDays, stepDays) {
    const maxDays = Math.max(0, Number(totalDays) || 0);
    const step = Math.max(1, Number(stepDays) || 1);
    const out = [];

    for (let d = 0; d <= maxDays; d += step) {
      out.push(emaPointForDay(d));
    }

    if (out.length === 0 || out[out.length - 1].day !== maxDays) {
      out.push(emaPointForDay(maxDays));
    }

    return out;
  }

  function emaSeries10YearsMonthly() {
    const totalDays = EMA_MODEL.totalYears * EMA_MODEL.yearDays;
    return emaSeriesByDays(totalDays, 30);
  }

  function emaSeries10YearsDaily() {
    const totalDays = EMA_MODEL.totalYears * EMA_MODEL.yearDays;
    return emaSeriesByDays(totalDays, 1);
  }

  function emaMilestones() {
    return {
      start: {
        day: 0,
        price: emaFormatPrice(EMA_MODEL.basePrice),
        date_utc: '2026-03-01 00:00:00'
      },
      year1: {
        day: 365,
        price: emaFormatPrice(EMA_MODEL.basePrice * EMA_MODEL.year1Multiplier),
        date_utc: '2027-03-01 00:00:00'
      },
      year10: {
        day: 3650,
        price: emaFormatPrice(EMA_MODEL.basePrice * EMA_MODEL.totalTargetMultiplier),
        date_utc: '2036-02-27 00:00:00'
      }
    };
  }

  function emaBuildChartDataset(mode) {
    const series = mode === 'daily' ? emaSeries10YearsDaily() : emaSeries10YearsMonthly();

    return {
      labels: series.map(p => p.x),
      values: series.map(p => p.y),
      points: series
    };
  }

  function emaRenderChart(canvasId, mode) {
    if (typeof Chart === 'undefined') {
      console.error('[EMA CHART] Chart is not available');
      return null;
    }

    const canvas = document.getElementById(canvasId);
    if (!canvas) {
      console.error('[EMA CHART] Canvas not found:', canvasId);
      return null;
    }

    const ds = emaBuildChartDataset(mode || 'monthly');

    return new Chart(canvas, {
      type: 'line',
      data: {
        labels: ds.labels,
        datasets: [{
          label: 'EMA$',
          data: ds.values,
          borderWidth: 2,
          pointRadius: 0,
          tension: 0.25,
          fill: false
        }]
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
                return 'EMA$ ' + emaFormatPrice(ctx.parsed.y, EMA_MODEL.displayDp);
              }
            }
          }
        },
        scales: {
          x: {
            ticks: {
              maxTicksLimit: 8
            }
          },
          y: {
            ticks: {
              callback(value) {
                return emaFormatPrice(value, EMA_MODEL.displayDp);
              }
            }
          }
        }
      }
    });
  }

  window.POADO_EMA = {
    model: EMA_MODEL,
    dayIndex: emaDayIndex,
    priceByDay: emaPriceByDay,
    priceByDate: emaPriceByDate,
    formatPrice: emaFormatPrice,
    pointForDay: emaPointForDay,
    seriesByDays: emaSeriesByDays,
    series10YearsMonthly: emaSeries10YearsMonthly,
    series10YearsDaily: emaSeries10YearsDaily,
    milestones: emaMilestones,
    buildChartDataset: emaBuildChartDataset,
    renderChart: emaRenderChart
  };
})();