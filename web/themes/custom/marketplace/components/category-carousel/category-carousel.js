(function (Drupal, once) {
  Drupal.behaviors.marketplaceCategoryCarousel = {
    attach(context) {
      once('marketplace-category-carousel', '.category-carousel', context).forEach((carousel) => {
        const track = carousel.querySelector('[data-carousel-track]');
        const previous = carousel.querySelector('[data-carousel-prev]');
        const next = carousel.querySelector('[data-carousel-next]');

        if (!track || !previous || !next) return;

        const scroll = (direction) => {
          const card = track.firstElementChild;
          const gap = parseFloat(getComputedStyle(track).columnGap) || 0;
          const distance = card
            ? card.getBoundingClientRect().width + gap
            : track.clientWidth;

          track.scrollBy({
            left: distance * direction,
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches
              ? 'instant'
              : 'smooth'
          });
        };

        previous.addEventListener('click', () => scroll(-1));
        next.addEventListener('click', () => scroll(1));
      });
    }
  };
})(Drupal, once);
