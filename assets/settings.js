// Account appearance is stored on the server and applied on every device.
(() => {
  const app = window.LChat;
  if (!app) return;
  const {api, modal, report, toast} = app;
  const colors = ['#62a8ff','#8b8cff','#bb83ee','#ea83c9','#fa8097','#fa987c','#f3bb68','#c8ca70','#87cc82','#52d3aa','#56c7c9','#6bbbdc','#4f96e4','#7475d8','#aa71d9','#d77bb6','#e67883','#e49b68','#bbac72','#92ae79','#64ad91','#6da3a3','#798ebf','#a39aaf'];
  const defaults = {bio:'',theme:'dark',accent:0,compact:0};
  let current = {...defaults}, currentUser = 0, loadVersion = 0;
  const escapeText = value => String(value ?? '');
  function apply(value) {
    current = {...defaults,...value};
    const light = current.theme === 'light';
    document.documentElement.dataset.lchatTheme = light ? 'light' : 'dark';
    document.getElementById('dark-theme-style').disabled = light;
    document.getElementById('dark-details-style').disabled = light;
    document.documentElement.style.setProperty('--accent', colors[Number(current.accent)] || colors[0]);
    document.body.classList.toggle('compact-mode', Boolean(Number(current.compact)));
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', light ? '#f1f3f5' : '#0b1018');
  }
  try { apply(JSON.parse(localStorage.getItem('lchat-appearance') || '{}')); } catch { apply(defaults); }
  document.addEventListener('lchat-user-ready', async event => {
    const id = Number(event.detail.user?.id || 0), version = ++loadVersion;
    if (!id) { currentUser = 0; return; }
    currentUser = id;
    try {
      const response = await fetch('settings.php', {credentials:'same-origin', cache:'no-store'});
      if (!response.ok) return;
      const data = await response.json();
      if (version !== loadVersion || currentUser !== id) return;
      apply(data.settings);
      localStorage.setItem('lchat-appearance', JSON.stringify(data.settings));
    } catch { /* Keep the last chosen appearance while offline. */ }
  });
  document.getElementById('settings-button')?.addEventListener('click', async () => {
    if (!currentUser) return;
    let data;
    try {
      const response = await fetch('settings.php', {credentials:'same-origin', cache:'no-store'});
      data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Не удалось загрузить настройки.');
    } catch (error) { report(error); return; }
    modal('Настройки', body => {
      const form = document.createElement('form'); form.className = 'settings-form';
      const nameLabel = document.createElement('label'); nameLabel.textContent = 'Имя';
      const name = document.createElement('input'); name.name='name'; name.required=true; name.minLength=2; name.maxLength=60; name.value=escapeText(data.profile.name); nameLabel.append(name);
      const bioLabel = document.createElement('label'); bioLabel.textContent='О себе';
      const bio = document.createElement('textarea'); bio.name='bio'; bio.rows=3; bio.maxLength=240; bio.placeholder='Пара слов о себе'; bio.value=escapeText(data.settings.bio); bioLabel.append(bio);
      const themeLabel = document.createElement('label'); themeLabel.textContent='Тема';
      const theme = document.createElement('select'); theme.name='theme';
      for (const [value,label] of [['dark','Тёмная'],['light','Светлая']]) {const option=document.createElement('option');option.value=value;option.textContent=label;theme.append(option);} theme.value=data.settings.theme || 'dark'; themeLabel.append(theme);
      const paletteLabel = document.createElement('div'); paletteLabel.className='settings-label'; paletteLabel.textContent='Цвет акцента';
      const palette = document.createElement('div'); palette.className='settings-palette'; palette.setAttribute('role','radiogroup'); palette.setAttribute('aria-label','Цвет акцента');
      let chosen = Number(data.settings.accent) || 0;
      colors.forEach((color,index) => {const swatch=document.createElement('button');swatch.type='button';swatch.className='settings-swatch';swatch.style.background=color;swatch.title='Цвет '+(index+1);swatch.setAttribute('role','radio');swatch.setAttribute('aria-checked',String(index===chosen));swatch.onclick=()=>{chosen=index;palette.querySelectorAll('[role=radio]').forEach((item,i)=>item.setAttribute('aria-checked',String(i===index)));};palette.append(swatch);});
      const compactLabel=document.createElement('label');compactLabel.className='settings-check';const compact=document.createElement('input');compact.type='checkbox';compact.checked=Boolean(Number(data.settings.compact));compactLabel.append(compact,document.createTextNode(' Компактный список чатов'));
      const error=document.createElement('p');error.className='form-error';error.hidden=true;error.setAttribute('role','alert');
      const save=document.createElement('button');save.type='submit';save.className='primary wide';save.textContent='Сохранить настройки';
      form.append(nameLabel,bioLabel,themeLabel,paletteLabel,palette,compactLabel,error,save);
      form.onsubmit=async event=>{event.preventDefault();save.disabled=true;error.hidden=true;try{const before=await api('me');const response=await fetch('settings.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':before.csrf},body:JSON.stringify({name:name.value,bio:bio.value,theme:theme.value,accent:chosen,compact:compact.checked})});const result=await response.json();if(!response.ok)throw new Error(result.error||'Не удалось сохранить настройки.');const value={bio:bio.value,theme:theme.value,accent:chosen,compact:compact.checked?1:0};apply(value);localStorage.setItem('lchat-appearance',JSON.stringify(value));const user=app.getUser();if(user)user.name=name.value.trim();const avatar=document.getElementById('profile-button');avatar.title=user?.name||name.value.trim();avatar.firstChild && avatar.firstChild.nodeType===3 && (avatar.firstChild.textContent=(user?.name||name.value.trim()).slice(0,1).toUpperCase());document.getElementById('modal').close();toast('Настройки сохранены');}catch(e){error.textContent=e.message||'Не удалось сохранить настройки.';error.hidden=false;}finally{save.disabled=false;}};
      body.append(form);
    });
  });
  if ('serviceWorker' in navigator && location.protocol === 'https:') {
    window.addEventListener('load', () => navigator.serviceWorker.register('/service-worker.js').catch(() => {}));
  }
})();
