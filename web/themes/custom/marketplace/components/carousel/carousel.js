(function (Drupal, once) {
  Drupal.behaviors.marketplaceCarousel = {
    attach(context) {
      once('marketplace-carousel', '.mp-carousel', context).forEach((carousel) => {
        const track = carousel.querySelector('[data-carousel-track]');
        const prev = carousel.querySelector('[data-carousel-prev]');
        const next = carousel.querySelector('[data-carousel-next]');
        const dots = carousel.querySelector('[data-carousel-dots]');

        if (!track) return;

        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        const motion = () => reducedMotion.matches ? 'instant' : 'smooth';

        const getStep = () => {
          const card = track.firstElementChild;
          if (!card) return track.clientWidth;

          const gap = parseFloat(getComputedStyle(track).columnGap) || 0;
          return card.getBoundingClientRect().width + gap;
        };

        const getPositions = () => {
          const max = Math.max(0, track.scrollWidth - track.clientWidth);
          const step = getStep();
          const positions = [0];

          if (step <= 0) return positions;

          for (let position = step; position < max - 2; position += step) {
            positions.push(position);
          }

          if (max > 2) positions.push(max);
          return positions;
        };

        const currentIndex = (positions) => {
          let nearest = 0;
          let distance = Infinity;

          positions.forEach((position, index) => {
            const difference = Math.abs(track.scrollLeft - position);
            if (difference < distance) {
              distance = difference;
              nearest = index;
            }
          });

          return nearest;
        };

        const update = () => {
          const max = Math.max(0, track.scrollWidth - track.clientWidth);

          if (prev) prev.disabled = track.scrollLeft <= 2;
          if (next) next.disabled = track.scrollLeft >= max - 2;

          if (dots) {
            const positions = getPositions();
            const active = currentIndex(positions);

            if (dots.children.length !== positions.length) {
              dots.replaceChildren();

              positions.forEach((position, index) => {
                const dot = document.createElement('button');
                dot.type = 'button';
                dot.className = 'mp-carousel__dot';
                dot.setAttribute('aria-label', `Go to carousel position ${index + 1}`);
                dot.addEventListener('click', () => {
                  track.scrollTo({ left: getPositions()[index], behavior: motion() });
                });
                dots.appendChild(dot);
              });
            }

            Array.from(dots.children).forEach((dot, index) => {
              if (index === active) {
                dot.setAttribute('aria-current', 'true');
              } else {
                dot.removeAttribute('aria-current');
              }
            });
          }
        };

        const scroll = (direction) => {
          const amount = carousel.dataset.scrollAmount === 'page'
            ? track.clientWidth
            : getStep();

          track.scrollBy({
            left: direction * amount,
            behavior: motion()
          });
        };

        if (prev) prev.addEventListener('click', () => scroll(-1));
        if (next) next.addEventListener('click', () => scroll(1));

        track.addEventListener('scroll', update, { passive: true });

        if ('ResizeObserver' in window) {
          const observer = new ResizeObserver(update);
          observer.observe(track);
        } else {
          window.addEventListener('resize', update);
        }

        update();
      });
    }
  };
})(Drupal, once);
