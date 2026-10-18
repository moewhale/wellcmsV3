/**
 * DUX Demo - 主题与交互脚本
 * 暗色模式逻辑与 WellCMS v3 (app/views/js/core/WellCMSTheme.js) 保持一致：
 * localStorage.theme = 'dark' | 'light'，<html> 根元素切换 .dark class
 */
const wellcms = {
  initTheme() {
    if (
      localStorage.theme === 'dark' ||
      (!('theme' in localStorage) &&
        window.matchMedia('(prefers-color-scheme: dark)').matches)
    ) {
      document.documentElement.classList.add('dark');
    } else {
      document.documentElement.classList.remove('dark');
    }
  },
  toggleDarkMode() {
    if (document.documentElement.classList.contains('dark')) {
      document.documentElement.classList.remove('dark');
      localStorage.theme = 'light';
    } else {
      document.documentElement.classList.add('dark');
      localStorage.theme = 'dark';
    }
  }
};

/* 防止闪烁：渲染前初始化主题 */
wellcms.initTheme();

document.addEventListener('DOMContentLoaded', function () {
  /* 移动端菜单 */
  var toggle = document.getElementById('mobile-menu-toggle');
  var nav = document.getElementById('main-nav');
  var backdrop = document.getElementById('nav-backdrop');
  var close = document.getElementById('nav-close');
  function openNav() {
    if (!nav) return;
    nav.classList.remove('-translate-x-full');
    if (backdrop) { backdrop.classList.remove('hidden'); requestAnimationFrame(function(){ backdrop.classList.remove('opacity-0'); }); }
    document.body.style.overflow = 'hidden';
  }
  function closeNav() {
    if (!nav) return;
    nav.classList.add('-translate-x-full');
    if (backdrop) { backdrop.classList.add('opacity-0'); setTimeout(function(){ backdrop.classList.add('hidden'); }, 300); }
    document.body.style.overflow = '';
  }
  if (toggle) toggle.addEventListener('click', openNav);
  if (close) close.addEventListener('click', closeNav);
  if (backdrop) backdrop.addEventListener('click', closeNav);

  /* 焦点图轮播（极简版） */
  document.querySelectorAll('[data-slider]').forEach(function (slider) {
    var slides = slider.querySelectorAll('[data-slide]');
    var dots = slider.querySelectorAll('[data-dot]');
    if (slides.length < 2) return;
    var i = 0, timer;
    function show(n) {
      i = (n + slides.length) % slides.length;
      slides.forEach(function (s, k) {
        s.classList.toggle('opacity-100', k === i);
        s.classList.toggle('opacity-0', k !== i);
        s.classList.toggle('pointer-events-none', k !== i);
      });
      dots.forEach(function (d, k) {
        d.classList.toggle('bg-white', k === i);
        d.classList.toggle('w-6', k === i);
        d.classList.toggle('bg-white/50', k !== i);
        d.classList.toggle('w-2.5', k !== i);
      });
    }
    function play() { timer = setInterval(function () { show(i + 1); }, 4000); }
    slider.querySelector('[data-prev]') && slider.querySelector('[data-prev]').addEventListener('click', function () { show(i - 1); });
    slider.querySelector('[data-next]') && slider.querySelector('[data-next]').addEventListener('click', function () { show(i + 1); });
    dots.forEach(function (d, k) { d.addEventListener('click', function () { show(k); }); });
    slider.addEventListener('mouseenter', function () { clearInterval(timer); });
    slider.addEventListener('mouseleave', play);
    show(0); play();
  });

  /* 回到顶部 */
  var topBtn = document.getElementById('back-to-top');
  if (topBtn) {
    window.addEventListener('scroll', function () {
      topBtn.classList.toggle('opacity-0', window.scrollY < 300);
      topBtn.classList.toggle('pointer-events-none', window.scrollY < 300);
    });
    topBtn.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }
});
