<?php
/**
 * Plugin Name: Kvin Site Assistant
 * Description: Локальный чат-ассистент Квин для ipkorotkov.online.
 * Version: 1.0.0
 * Author: Konstantin Korotkov
 */
if (!defined('ABSPATH')) { exit; }
add_action('wp_footer', function () {
    if (is_admin()) return;
    ?>
    <div id="kvin-site-assistant" aria-live="polite">
      <style>
      #kvin-site-assistant{--bg:#0e1322;--panel:#131a2c;--ink:#eef2f8;--muted:#93a0bb;font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink)}
      #kvin-site-assistant *{box-sizing:border-box}
      #kvin-site-assistant .kv-launch{position:fixed;right:22px;bottom:22px;z-index:99990;border:0;border-radius:16px;padding:15px 20px;background:linear-gradient(135deg,#62e2d4,#69a8ff,#b49aff);color:#07101d;font-weight:750;box-shadow:0 10px 30px #0008;cursor:pointer}
      #kvin-site-assistant .kv-panel{position:fixed;right:22px;bottom:88px;z-index:99991;width:390px;max-width:calc(100vw - 28px);height:min(610px,calc(100vh - 112px));display:none;flex-direction:column;overflow:hidden;border-radius:20px;background:var(--bg);box-shadow:0 25px 70px #000b}
      #kvin-site-assistant.open .kv-panel{display:flex}
      #kvin-site-assistant .kv-head{display:flex;align-items:center;gap:10px;padding:14px;background:#ffffff09}
      #kvin-site-assistant .kv-logo{display:grid;place-items:center;width:36px;height:36px;border-radius:11px;background:linear-gradient(135deg,#62e2d4,#69a8ff,#b49aff);color:#07101d;font-weight:800}
      #kvin-site-assistant .kv-head strong{flex:1}
      #kvin-site-assistant .kv-close{border:0;border-radius:9px;padding:8px 11px;background:#ffffff12;color:white;cursor:pointer}
      #kvin-site-assistant .kv-body{flex:1;overflow:auto;padding:14px;display:flex;flex-direction:column;gap:10px}
      #kvin-site-assistant .kv-msg{max-width:88%;padding:11px 13px;border-radius:14px;background:var(--panel);white-space:pre-wrap;overflow-wrap:anywhere}
      #kvin-site-assistant .kv-msg.me{align-self:flex-end;background:#34527b}
      #kvin-site-assistant .kv-actions{display:flex;flex-wrap:wrap;gap:7px;padding:0 14px 8px}
      #kvin-site-assistant .kv-actions button,#kvin-site-assistant .kv-send{border:0;border-radius:10px;padding:9px 12px;background:#ffffff12;color:var(--ink);cursor:pointer}
      #kvin-site-assistant .kv-actions button.primary,#kvin-site-assistant .kv-send{background:linear-gradient(135deg,#62e2d4,#69a8ff);color:#07101d;font-weight:700}
      #kvin-site-assistant .kv-composer{display:flex;gap:8px;padding:12px}
      #kvin-site-assistant textarea,#kvin-site-assistant input{width:100%;min-width:0;border:1px solid #ffffff24;border-radius:11px;background:#090e19;color:white;padding:12px;font:inherit}
      #kvin-site-assistant .kv-composer textarea{resize:none;max-height:100px}
      #kvin-site-assistant .kv-form{display:grid;gap:9px;padding:13px;background:var(--panel);border-radius:14px}
      #kvin-site-assistant .kv-form label{display:grid;gap:5px;color:var(--muted);font-size:13px}
      #kvin-site-assistant .kv-form button{border:0;border-radius:10px;padding:12px;background:linear-gradient(135deg,#62e2d4,#69a8ff);color:#07101d;font-weight:700;cursor:pointer}
      #kvin-site-assistant .kv-note{padding:0 14px 12px;text-align:center;color:#71809c;font-size:11px}
      @media(max-width:640px){#kvin-site-assistant .kv-panel{left:8px;right:8px;bottom:8px;width:auto;max-width:none;height:calc(100dvh - 16px)}#kvin-site-assistant .kv-launch{right:14px;bottom:14px}}
      </style>
      <button class="kv-launch" type="button" aria-expanded="false">✦ Квин · Помощник</button>
      <section class="kv-panel" role="dialog" aria-label="Квин — ассистент сайта">
        <header class="kv-head"><span class="kv-logo">К</span><strong>Квин<small style="display:block;color:#93a0bb;font-weight:400">Ассистент сайта · локальная база знаний</small></strong><button class="kv-close" type="button" aria-label="Закрыть">×</button></header>
        <div class="kv-body"></div><div class="kv-actions"></div>
        <div class="kv-composer"><textarea rows="1" aria-label="Сообщение" placeholder="Опишите задачу или задайте вопрос…"></textarea><button class="kv-send" type="button" aria-label="Отправить">➤</button></div>
        <div class="kv-note">Справочная информация. Сроки и стоимость уточняются после описания задачи.</div>
      </section>
    </div>
    <script>
    (function(){
    const root=document.getElementById('kvin-site-assistant');if(!root||root.dataset.ready)return;root.dataset.ready='1';
    const panel=root.querySelector('.kv-panel'),body=root.querySelector('.kv-body'),input=root.querySelector('textarea'),launch=root.querySelector('.kv-launch'),quick=root.querySelector('.kv-actions');
    const services=[
    ['Настройка сервера','Linux, VPS/VDS, Nginx, Docker, SSL, резервное копирование и мониторинг.',['подбор конфигурации','развёртывание и настройка','защита и резервные копии'],'/услуги-по-настройке-сервера/'],
    ['Терминальный сервер RDS','Настройка удалённых рабочих столов Windows Server.',['RDSH, Gateway и лицензирование','профили пользователей','безопасный удалённый доступ'],''],
    ['Настройка 1С и доработки','Отчёты, обработки, печатные формы, расширения и интеграции.',['оценка до начала работ','разработка на копии базы','инструкция по результату'],''],
    ['IT-поддержка бизнеса','Удалённая помощь с компьютерами, серверами и рабочими сервисами.',['диагностика','устранение ошибок','сопровождение'],''],
    ['Автоматизация и парсеры','Сбор данных, мониторинг цен, API, Telegram-боты и сайты.',['автоматизация повторяющихся операций','интеграции','веб-инструменты'],'']
    ];
    function add(text,who){const el=document.createElement('div');el.className='kv-msg '+(who||'bot');el.textContent=text;body.appendChild(el);body.scrollTop=body.scrollHeight;return el}
    function actions(items){quick.replaceChildren();items.forEach(x=>{const b=document.createElement('button');b.textContent=x[0];if(x[2])b.className='primary';b.onclick=()=>act(x[1]);quick.appendChild(b)})}
    function act(kind){if(kind==='services'){add('Покажи услуги','me');services.forEach(s=>{const el=add(s[0]+'\n'+s[1]+'\n• '+s[2].join('\n• '));if(s[3]){const a=document.createElement('a');a.href=s[3];a.textContent='Открыть страницу услуги';a.style.display='inline-block';a.style.marginTop='8px';el.appendChild(a)}});actions([['Стоимость','ask:price'],['Сроки','ask:time'],['Оставить заявку','lead']]);return}if(kind==='lead'){lead();return}if(kind.indexOf('ask:')===0){send(kind==='ask:price'?'Сколько стоят услуги?':'Какие сроки выполнения работ?');return}send(kind)}
    function lead(){const f=document.createElement('form');f.className='kv-form';f.innerHTML='<strong>Обсудить задачу</strong><label>Имя<input name="name" required minlength="2" placeholder="Как к вам обращаться"></label><label>Телефон или мессенджер<input name="contact" required minlength="5" placeholder="+7… / @username"></label><label>Задача<textarea name="task" required minlength="10" rows="3" placeholder="Что нужно сделать?"></textarea></label><label style="display:flex;grid-template-columns:18px 1fr;align-items:start"><input type="checkbox" name="consent" required style="width:16px">Согласен на обработку персональных данных</label><button>Подготовить заявку</button><small style="color:#93a0bb">Откроется почтовая программа для подтверждения отправки.</small>';body.appendChild(f);f.onsubmit=e=>{e.preventDefault();const d=new FormData(f),txt='Имя: '+d.get('name')+'\nКонтакт: '+d.get('contact')+'\nЗадача: '+d.get('task')+'\nСтраница: '+location.href;location.href='mailto:tochka_rosta24@list.ru?subject='+encodeURIComponent('Заявка с сайта — Квин')+'&body='+encodeURIComponent(txt);add('Заявка подготовлена. Подтвердите отправку в почтовой программе.');f.remove()};body.scrollTop=body.scrollHeight}
    function reply(t){const s=t.toLowerCase().replace(/ё/g,'е');if(/привет|здрав|добрый|помоги|кто ты/.test(s))return 'Я Квин — помощник сайта. Подскажу по настройке серверов, RDS, 1С, IT-поддержке, автоматизации, парсерам и сайтам.';if(/цен|стоим|сколько|бюджет|прайс/.test(s))return 'Стоимость зависит от объёма, конфигурации и срочности. Опишите задачу — подготовлю исходные данные для предварительной оценки. Точная стоимость согласуется до начала работ.';if(/срок|когда|долго|быстро/.test(s))return 'Срок зависит от объёма и готовности доступов. Небольшие настройки обычно занимают несколько дней, сложные внедрения выполняются поэтапно. Расскажите подробнее о задаче.';if(/rds|rdp|терминал|удаленн|удалённ/.test(s))return services[1][1]+' Могу помочь рассчитать ресурсы и состав работ.';if(/1с|1c|erp|отчет|отчёт|обработк|программист/.test(s))return services[2][1]+' Работы оцениваются до старта, разработка ведётся на копии базы.';if(/сервер|vps|vds|linux|nginx|docker|ssl|бэкап|vpn/.test(s))return services[0][1]+' Не предлагаю аренду сервера — помогаю настроить выбранную инфраструктуру.';if(/парсер|парсинг|бот|автоматиз|интеграц|crm|сайт/.test(s))return services[4][1];if(/город|москв|регион|очно|удален|удалён|онлайн/.test(s))return 'Работаю удалённо, поэтому география не ограничивает сотрудничество.';return 'Чтобы ответить предметно, уточните направление: серверы, терминальный сервер RDS, 1С, IT-поддержка или автоматизация. Можете сразу описать задачу.'}
    function send(t){t=(t||'').trim();if(!t)return;add(t,'me');const a=add(reply(t));const b=document.createElement('button');b.textContent='Обсудить задачу';b.className='kv-send';b.style.marginTop='9px';b.onclick=()=>lead();a.appendChild(b);actions([['Все услуги','services'],['Стоимость','ask:price'],['Сроки','ask:time'],['Оставить заявку','lead']]);input.value=''}
    launch.onclick=()=>{root.classList.toggle('open');launch.setAttribute('aria-expanded',root.classList.contains('open')?'true':'false');if(root.classList.contains('open')&&!body.children.length){add('Здравствуйте! Я Квин — ассистент сайта. Помогу выбрать услугу, разобраться с задачей и подготовить заявку.');actions([['Показать услуги','services'],['Настройка сервера','Настройка сервера'],['1С и доработки','1С'],['Стоимость','ask:price'],['Оставить заявку','lead']]);input.focus()}};
    root.querySelector('.kv-close').onclick=()=>{root.classList.remove('open');launch.setAttribute('aria-expanded','false')};
    root.querySelector('.kv-send').onclick=()=>send(input.value);input.onkeydown=e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send(input.value)}};
    document.addEventListener('keydown',e=>{if(e.key==='Escape')root.classList.remove('open')});
    })();
    </script>
    <?php
}, 99);
