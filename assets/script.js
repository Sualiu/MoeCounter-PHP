(function () {
  const btn = document.getElementById('get');
  const img = document.getElementById('result');
  const code = document.getElementById('code');

  const elements = {
    name: document.getElementById('name'),
    theme: document.getElementById('theme'),
    padding: document.getElementById('padding'),
    offset: document.getElementById('offset'),
    align: document.getElementById('align'),
    scale: document.getElementById('scale'),
    pixelated: document.getElementById('pixelated'),
    darkmode: document.getElementById('darkmode'),
    num: document.getElementById('num'),
    prefix: document.getElementById('prefix')
  };

  btn.addEventListener('click', throttle(handleButtonClick, 500));
  code.addEventListener('click', selectCodeText);

  const mainTitle = document.querySelector('#main_title i');
  const themes = document.querySelector('#themes');
  const moreTheme = document.querySelector('#more_theme');

  mainTitle.addEventListener('click', throttle(() => party.sparkles(document.documentElement, { count: party.variation.range(40, 100) }), 1000));
  moreTheme.addEventListener('click', scrollToThemes);

  function handleButtonClick() {
    const { name, theme, padding, offset, scale, pixelated, darkmode, num } = elements;
    const nameValue = name.value.trim();

    if (!nameValue) {
      alert('Please input counter name.');
      return;
    }

    const params = {
      name: nameValue,
      theme: theme.value || 'moebooru',
      padding: padding.value || '7',
      offset: offset.value || '0',
      align: align.value || 'top',
      scale: scale.value || '1',
      pixelated: pixelated.checked ? '1' : '0',
      darkmode: darkmode.value || 'auto'
    };

    if (num.value > 0) {
      params.num = num.value;
    }
    if (prefix.value !== '') {
      params.prefix = prefix.value;
    }

    const query = new URLSearchParams(params).toString();
    const imgSrc = `${__global_data.site}/@${nameValue}?${query}`;

    img.src = `${imgSrc}&_=${Math.random()}`;
    btn.setAttribute('disabled', '');

    img.onload = () => {
      img.scrollIntoView({ block: 'start', behavior: 'smooth' });
      code.textContent = imgSrc;
      code.style.visibility = 'visible';
      party.confetti(btn, { count: party.variation.range(20, 40) });
      btn.removeAttribute('disabled');
    };

    img.onerror = async () => {
      try {
        const res = await fetch(img.src);
        if (!res.ok) {
          const { message } = await res.json();
          alert(message);
        }
      } finally {
        btn.removeAttribute('disabled');
      }
    };
  }

  function selectCodeText(e) {
    e.preventDefault();
    e.stopPropagation();

    const target = e.target;
    const range = document.createRange();
    const selection = window.getSelection();

    range.selectNodeContents(target);
    selection.removeAllRanges();
    selection.addRange(range);
  }

  function scrollToThemes() {
    if (!themes.hasAttribute('open')) {
      party.sparkles(moreTheme.querySelector('h3'), { count: party.variation.range(20, 40) });
      themes.scrollIntoView({ block: 'start', behavior: 'smooth' });
    }
  }

  function throttle(fn, threshold = 250) {
    let last, deferTimer;
    return function (...args) {
      const now = Date.now();
      if (last && now < last + threshold) {
        clearTimeout(deferTimer);
        deferTimer = setTimeout(() => {
          last = now;
          fn.apply(this, args);
        }, threshold);
      } else {
        last = now;
        fn.apply(this, args);
      }
    };
  }
})();

// Lazy Load
(() => {
  function lazyLoad(options = {}) {
    const { selector = 'img[data-src]:not([src])', loading = '', failed = '', rootMargin = '200px', threshold = 0.01 } = options;

    const images = document.querySelectorAll(selector);

    const observer = new IntersectionObserver((entries, observer) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          const img = entry.target;
          observer.unobserve(img);

          img.onerror = failed ? () => { img.src = failed; img.setAttribute('data-failed', ''); } : null;
          img.src = img.getAttribute('data-src');
          img.removeAttribute('data-loading');
        }
      });
    }, { rootMargin, threshold });

    images.forEach(img => {
      if (loading) {
        img.src = loading;
        img.setAttribute('data-loading', '');
      }
      observer.observe(img);
    });
  }

  const lazyLoadOptions = {
    selector: 'img[data-src]:not([src])',
    loading: `${__global_data.site}/assets/img/loading.svg`,
    failed: `${__global_data.site}/assets/img/failed.svg`,
    rootMargin: '200px',
    threshold: 0.01
  };

  document.readyState === 'loading'
    ? document.addEventListener("DOMContentLoaded", () => lazyLoad(lazyLoadOptions))
    : lazyLoad(lazyLoadOptions);
})();

// Back to top
(() => {
  let isShow = false, lock = false;
  const btn = document.querySelector('.back-to-top');
  
  // 确保按钮元素存在
  if (!btn) return;

  const handleScroll = () => {
    if (lock) return;
    
    // 使用多个方式获取滚动位置，确保跨浏览器兼容性
    const scrollTop = window.pageYOffset || 
                     document.documentElement.scrollTop || 
                     document.body.scrollTop || 0;
    
    if (scrollTop >= 1000) {
      if (!isShow) {
        requestAnimationFrame(() => {
          btn.classList.add('load');
          isShow = true;
        });
      }
    } else if (isShow) {
      requestAnimationFrame(() => {
        btn.classList.remove('load');
        isShow = false;
      });
    }
  };

  const handleClick = () => {
    if (lock) return;
    
    lock = true;
    btn.classList.add('ani-leave');
    
    // 使用 scrollTo 滚动到顶部
    window.scrollTo({ 
      top: 0, 
      behavior: 'smooth' 
    });

    // 动画时序控制
    const animations = [
      { time: 390, fn: () => {
        btn.classList.remove('ani-leave');
        btn.classList.add('leaved');
      }},
      { time: 120, fn: () => btn.classList.add('ending')},
      { time: 1500, fn: () => btn.classList.remove('load')},
      { time: 2000, fn: () => {
        lock = false;
        isShow = false;
        btn.classList.remove('leaved', 'ending');
      }}
    ];

    animations.forEach(({time, fn}) => {
      setTimeout(fn, time);
    });
  };

  // 使用 passive 监听器优化滚动性能
  window.addEventListener('scroll', handleScroll, { passive: true });
  btn.addEventListener('click', handleClick);
  
  // 初始检查滚动位置
  handleScroll();
})();

// Prevent safari gesture
(() => {
  document.addEventListener('gesturestart', e => e.preventDefault());
})();
