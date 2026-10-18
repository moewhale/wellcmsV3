(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var control = event.target.closest('[data-scroll-controls] [data-scroll-direction]');
        if (!control) { return; }

        var scroller = document.scrollingElement;
        if (!scroller) { return; }

        window.scrollTo({
            top: control.dataset.scrollDirection === 'top' ? 0 : scroller.scrollHeight,
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'
        });
    });
}());
