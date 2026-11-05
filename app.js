document.addEventListener('DOMContentLoaded', function () {
    const groups = document.querySelectorAll('.rating-group');
    const totalEl = document.getElementById('totalScore');
    const avgEl = document.getElementById('averageRating');
    if (!groups.length || !totalEl) return;

    function recalc() {
        let sum = 0, count = 0;
        groups.forEach(g => {
            const checked = g.querySelector('input[type="radio"]:checked');
            if (checked) {
                sum += parseInt(checked.value, 10);
                count++;
            }
        });
        totalEl.textContent = sum;
        avgEl.textContent = count > 0 ? (sum / count).toFixed(2) : '0.00';
    }

    groups.forEach(g => {
        g.querySelectorAll('input[type="radio"]').forEach(r => {
            r.addEventListener('change', recalc);
        });
    });
    recalc();
});
