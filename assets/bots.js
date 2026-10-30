(() => {
  const app = window.LChat;
  if (!app) return;
  const {api, modal, node, button, avatar, toast, report, refreshChats, selectChat} = app;
  const byId = id => document.getElementById(id);
  async function request(action = '', data = null, retry = true) {
    const options = {credentials:'same-origin', cache:'no-store'};
    if (data) {
      const session = await api('me');
      options.method = 'POST';
      options.headers = {'Content-Type':'application/json', 'X-CSRF-Token':session.csrf};
      options.body = JSON.stringify({action, ...data});
    }
    const response = await fetch('bot-manage.php', options);
    const result = await response.json();
    if (!response.ok) {
      if (data && retry && response.status === 403 && result.error === 'Обновите страницу и повторите действие.') return request(action, data, false);
      throw new Error(result.error || 'Не удалось выполнить действие с ботом.');
    }
    return result;
  }
  function inputLabel(title, min, max, placeholder) {
    const label = document.createElement('label'); label.textContent = title;
    const input = document.createElement('input'); input.required = true; input.minLength = min; input.maxLength = max; input.placeholder = placeholder;
    label.append(input); return [label, input];
  }
  function showToken(title, token) {
    modal(title, body => {
      body.append(node('p', 'muted', 'Скопируйте токен сейчас. Позже он не показывается; его можно только заменить.'));
      const input = document.createElement('input'); input.className = 'bot-token'; input.readOnly = true; input.value = token; input.setAttribute('aria-label','Токен бота');
      body.append(input, button('Скопировать токен','primary wide',async () => {await navigator.clipboard.writeText(token);toast('Токен скопирован');}));
      body.append(node('p','muted small','Bot API: https://chat.lbs.su/bot<TOKEN>/getMe. Никому не передавайте токен.'));
      body.append(button('Готово','button subtle wide',openBots));
    });
  }
  async function startChat(bot) {
    const created = await api('chat.direct', {user_id:bot.id}, true);
    byId('modal').close();
    await refreshChats();
    const chat = app.getChats().find(item => Number(item.id) === Number(created.id));
    if (chat) await selectChat(chat);
  }
  function addDialog(bot) {
    const owned = app.getChats().filter(chat => ['group','channel'].includes(chat.kind) && chat.role === 'owner');
    modal('Добавить @' + bot.username, body => {
      if (!owned.length) {body.append(node('p','muted','Сначала создайте группу или канал, владельцем которого будете вы.'));return;}
      body.append(node('p','muted small','Бот увидит новые сообщения после добавления.'));
      const form = document.createElement('form'); form.className = 'bot-add-form';
      const select = document.createElement('select'); select.required = true;
      for (const chat of owned) {const option = document.createElement('option');option.value = chat.id;option.textContent = (chat.kind === 'channel' ? 'Канал · ' : 'Группа · ') + chat.title;select.append(option);}
      const submit = document.createElement('button');submit.type = 'submit';submit.className = 'primary wide';submit.textContent = 'Добавить бота';
      form.append(select, submit);
      form.onsubmit = async event => {event.preventDefault();submit.disabled=true;try{await request('add',{bot_id:bot.id,chat_id:Number(select.value)});toast('Бот добавлен');await refreshChats();await openBots();}catch(error){report(error);}finally{submit.disabled=false;}};
      body.append(form);
    });
  }
  async function openBots() {
    const data = await request();
    modal('Мои боты', body => {
      body.append(node('p','muted small','Создайте бота и получите API-токен через @BotCreator или форму ниже. Боты получают сообщения в личном чате и группах.'));
      if (data.creator_id) body.append(button('Написать @BotCreator','primary wide',async()=>{await startChat({id:data.creator_id});const input=byId('message-input');if(input){input.value='/start';input.focus();}}));
      body.append(button('Документация Bot API','button subtle wide',()=>{window.location.href='docs.php';}));
      for (const bot of data.bots) {
        const card = node('div','bot-card');
        const heading = node('div','bot-card-heading');heading.append(avatar(bot.name,bot.id),node('div','',bot.name+' · @'+bot.username));card.append(heading);
        const actions = node('div','bot-card-actions');
        actions.append(button('Открыть чат','button subtle',()=>startChat(bot)),button('Добавить в группу','button subtle',()=>addDialog(bot)),button('Новый токен','button subtle',async()=>{if(!confirm('Старый токен сразу перестанет работать. Заменить его?'))return;const changed=await request('rotate',{bot_id:bot.id});showToken('Новый токен @'+bot.username,changed.token);}));
        card.append(actions);body.append(card);
      }
      if (!data.bots.length) body.append(node('p','muted','Вы ещё не создали ботов.'));
      const form = document.createElement('form');form.className='bot-create-form';
      const [nameLabel,name] = inputLabel('Имя бота',2,60,'Например, Помощник');
      const [userLabel,username] = inputLabel('Логин бота',6,32,'Например, helperbot');
      const submit = document.createElement('button');submit.type='submit';submit.className='primary wide';submit.textContent='Создать бота';
      form.append(nameLabel,userLabel,node('p','muted small','Логин должен заканчиваться на bot.'),submit);
      form.onsubmit=async event=>{event.preventDefault();submit.disabled=true;try{const created=await request('create',{name:name.value,username:username.value});showToken('Токен @'+created.username,created.token);}catch(error){report(error);}finally{submit.disabled=false;}};
      body.append(form);
    });
  }
  byId('bots-button')?.addEventListener('click',()=>openBots().catch(report));
})();
