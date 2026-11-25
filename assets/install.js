// Installeringen: søk i tjenestene og tell hvor mange som er valgt.
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var sok = document.getElementById('kildeSok'), teller = document.getElementById('antallValgt');
    if (!sok) return;
    var valg = Array.prototype.slice.call(document.querySelectorAll('.kildevalg'));
    function tell() {
      var n = document.querySelectorAll('.kildevalg input:checked').length;
      teller.textContent = n + (n === 1 ? ' tjeneste valgt' : ' tjenester valgt');
    }
    sok.addEventListener('input', function () {
      var q = sok.value.trim().toLowerCase();
      valg.forEach(function (l) { l.hidden = q !== '' && l.textContent.toLowerCase().indexOf(q) < 0; });
      document.querySelectorAll('.kildegruppe').forEach(function (g) {
        g.hidden = !g.querySelector('.kildevalg:not([hidden])');
      });
    });
    document.addEventListener('change', tell);
    tell();
  });
})();
