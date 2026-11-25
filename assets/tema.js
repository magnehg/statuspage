// Settes før siden tegnes, så den ikke blinker i feil tema. Valget huskes i localStorage (st_tema).
(function () {
  var t = null;
  try { t = localStorage.getItem('st_tema'); } catch (e) {}
  if (['mork', 'varm', 'blagronn'].indexOf(t) < 0) {
    t = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'mork' : 'varm';
  }
  document.documentElement.setAttribute('data-tema', t);
})();
