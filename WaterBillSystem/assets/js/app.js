window.WB = (function () {
  'use strict';

  const fmtMoney = (n) =>
    '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  const confirmAction = (msg) => window.confirm(msg || 'Are you sure?');

  function debounce(fn, ms = 300) {
    let t;
    return function (...args) {
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), ms);
    };
  }

  return { fmtMoney, confirmAction, debounce };
})();