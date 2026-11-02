(function(){
  var btn=document.getElementById('menu-btn'), nav=document.getElementById('nav');
  if(!btn||!nav) return;
  function set(open){nav.classList.toggle('open',open);btn.setAttribute('aria-expanded',open);btn.textContent=open?'Schließen':'Menü';}
  btn.addEventListener('click',function(){set(!nav.classList.contains('open'));});
  nav.addEventListener('click',function(e){if(e.target.tagName==='A') set(false);});
})();
