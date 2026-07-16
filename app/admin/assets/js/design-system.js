(function () {
    if (typeof Chart === 'undefined') return;

    var brg    = getComputedStyle(document.documentElement).getPropertyValue('--er-primary').trim() || '#00563F';
    var yellow = getComputedStyle(document.documentElement).getPropertyValue('--er-accent').trim()   || '#FFF200';
    var ink    = getComputedStyle(document.documentElement).getPropertyValue('--er-link').trim()     || '#0B5394';
    var inkRgb = getComputedStyle(document.documentElement).getPropertyValue('--er-link-rgb').trim() || '11, 83, 148';

    var bar = document.getElementById('er-bar-demo');
    if (bar) {
        new Chart(bar.getContext('2d'), {
            type: 'bar',
            data: {
                labels: ['1963', '1964', '1965', '1966', '1967', '1968', '1969'],
                datasets: [{
                    label: 'Cars built',
                    data: [180, 245, 320, 410, 290, 215, 175],
                    backgroundColor: [brg, brg, brg, yellow, brg, brg, brg],
                    borderColor: brg,
                    borderWidth: 0
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
        });
    }

    var line = document.getElementById('er-line-demo');
    if (line) {
        new Chart(line.getContext('2d'), {
            type: 'line',
            data: {
                labels: ['2018', '2019', '2020', '2021', '2022', '2023', '2024', '2025', '2026'],
                datasets: [{
                    label: 'Total registered',
                    data: [820, 905, 970, 1040, 1115, 1180, 1220, 1245, 1259],
                    borderColor: ink,
                    backgroundColor: 'rgba(' + inkRgb + ', 0.1)',
                    fill: true,
                    tension: 0.3
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
        });
    }
})();
