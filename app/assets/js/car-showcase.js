(function () {
    'use strict';

    const showcase = document.getElementById('car-showcase');
    if (!showcase) return;

    const slides = showcase.querySelectorAll('[data-showcase-slide]');
    if (slides.length <= 1) return;

    let current = 0;
    let animating = false;
    const total = slides.length;
    const FADE_MS = 300;
    const ROTATE_MS = 5000;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const counter = document.getElementById('showcase-counter');
    const prevBtn = document.getElementById('showcase-prev');
    const nextBtn = document.getElementById('showcase-next');

    function goTo(index) {
        if (animating) return;
        const next = ((index % total) + total) % total;
        if (next === current) return;

        const outgoing = slides[current];
        const incoming = slides[next];
        if (!outgoing || !incoming) return;
        current = next;

        if (counter) {
            counter.textContent = (current + 1) + ' / ' + total;
        }

        if (reducedMotion) {
            outgoing.classList.add('d-none');
            incoming.classList.remove('d-none');
            return;
        }

        animating = true;
        outgoing.style.opacity = '0';

        setTimeout(function () {
            outgoing.classList.add('d-none');
            outgoing.style.opacity = '';

            incoming.style.opacity = '0';
            incoming.classList.remove('d-none');
            void incoming.offsetHeight; // force reflow so CSS transition fires
            incoming.style.opacity = '1';

            setTimeout(function () { animating = false; }, FADE_MS);
        }, FADE_MS);
    }

    if (counter) {
        counter.textContent = '1 / ' + total;
    }

    if (prevBtn) {
        prevBtn.addEventListener('click', function () { goTo(current - 1); });
    }
    if (nextBtn) {
        nextBtn.addEventListener('click', function () { goTo(current + 1); });
    }

    if (!reducedMotion) {
        let timer = setInterval(function () { goTo(current + 1); }, ROTATE_MS);

        function resetTimer() {
            clearInterval(timer);
            timer = setInterval(function () { goTo(current + 1); }, ROTATE_MS);
        }

        if (prevBtn) prevBtn.addEventListener('click', resetTimer);
        if (nextBtn) nextBtn.addEventListener('click', resetTimer);
    }
}());
