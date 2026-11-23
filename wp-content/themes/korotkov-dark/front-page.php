<?php
get_header();
$service_cards = array(
  array('num'=>'01','type'=>'WEB DEVELOPMENT','title'=>'Сайты и веб-сервисы','desc'=>'Корпоративные сайты, лендинги, каталоги и индивидуальные веб-инструменты.','url'=>'/services/','accent'=>'lime'),
  array('num'=>'02','type'=>'DATA & PARSING','title'=>'Парсеры и сбор данных','desc'=>'Сбор информации, обработка каталогов, мониторинг цен и автоматическое обновление данных.','url'=>'/services/parser-cen/','accent'=>'cyan'),
  array('num'=>'03','type'=>'AUTOMATION','title'=>'Интеграции и автоматизация','desc'=>'Связка сервисов, API, CRM и Telegram-боты для сокращения ручной работы.','url'=>'/services/','accent'=>'violet')
);
$pages = get_pages(array('sort_column'=>'menu_order,post_title','sort_order'=>'ASC','parent'=>0,'number'=>8));
$pages = array_filter($pages, function($p){ return !in_array($p->post_name, array('privacy-policy','blog'), true); });
?>
<main class="kd-main" id="content">
<section class="kd-hero kd-hero-v2">
 <div class="kd-wrap kd-hero-layout">
  <div class="kd-hero-copy">
   <span class="kd-eyebrow">Разработка · автоматизация · IT</span>
   <h1>Создаю цифровые инструменты, <span class="kd-gradient">которые экономят время</span></h1>
   <p class="kd-lead">Сайты, парсеры, интеграции и автоматизация бизнес-процессов — от идеи и технического задания до готового решения.</p>
   <div class="kd-hero-actions">
    <a class="kd-button" href="tel:+79641519362">Обсудить задачу <span aria-hidden="true">↗</span></a>
    <a class="kd-button kd-button-ghost" href="#projects">Смотреть направления <span aria-hidden="true">↓</span></a>
   </div>
   <div class="kd-proofline"><span><i></i> Работаю удалённо</span><span>Индивидуальная разработка</span><span>Поддержка после запуска</span></div>
  </div>
  <div class="kd-visual" aria-label="Иллюстрация процесса разработки">
   <div class="kd-orbit kd-orbit-one"></div><div class="kd-orbit kd-orbit-two"></div>
   <div class="kd-float kd-float-top"><span class="kd-float-icon">↗</span><span>DATA FLOW</span><b>Автоматизация</b></div>
   <div class="kd-code-window">
    <div class="kd-code-top"><span></span><span></span><span></span><small>solution.py</small></div>
    <div class="kd-code-body"><div><em>01</em> <i>from</i> tools <i>import</i> automate</div><div><em>02</em></div><div><em>03</em> <b>def</b> <strong>solve</strong>(task):</div><div class="indent"><em>04</em> data = collect(task)</div><div class="indent"><em>05</em> result = process(data)</div><div class="indent"><em>06</em> <b>return</b> result</div><div><em>07</em></div><div class="kd-code-success"><em>08</em> <span>✓ Решение готово к запуску</span></div></div>
   </div>
   <div class="kd-float kd-float-bottom"><span class="kd-pulse"></span><span>PROJECT STATUS</span><b>В работе → Результат</b></div>
   <div class="kd-visual-grid"></div>
  </div>
 </div>
 <div class="kd-wrap"><div class="kd-facts"><div><strong>01</strong><span>Погружение<br>в задачу</span></div><div><strong>02</strong><span>Разработка<br>и интеграция</span></div><div><strong>03</strong><span>Тестирование<br>и запуск</span></div><div class="kd-facts-note">Без лишней сложности.<br><b>С фокусом на результат.</b></div></div></div>
</section>

<section class="kd-section kd-services" id="projects">
 <div class="kd-wrap">
  <div class="kd-section-top"><div><span class="kd-eyebrow">Чем могу помочь</span><h2>Инструменты для <span class="kd-gradient">ваших задач</span></h2></div><p>Подбираю подход под конкретную задачу — без ненужных функций и сложных конструкций.</p></div>
  <div class="kd-grid kd-service-grid">
   <?php foreach($service_cards as $card): ?>
   <article class="kd-card kd-service-card kd-accent-<?php echo esc_attr($card['accent']); ?>">
    <div class="kd-service-top"><span><?php echo esc_html($card['num']); ?> / <?php echo esc_html($card['type']); ?></span><b aria-hidden="true"><?php echo $card['accent']==='lime'?'⌘':($card['accent']==='cyan'?'◫':'⌬'); ?></b></div>
    <h3><?php echo esc_html($card['title']); ?></h3><p><?php echo esc_html($card['desc']); ?></p>
    <a href="<?php echo esc_url(home_url($card['url'])); ?>">Подробнее <span aria-hidden="true">↗</span></a>
   </article>
   <?php endforeach; ?>
  </div>
 </div>
</section>

<section class="kd-section kd-pages-section">
 <div class="kd-wrap">
  <div class="kd-section-top"><div><span class="kd-eyebrow">Навигация</span><h2>Изучите <span class="kd-gradient">сайт и проекты</span></h2></div><p>Больше информации о направлениях работы, опыте и способах связи.</p></div>
  <div class="kd-grid kd-page-grid">
   <?php if($pages): foreach($pages as $p): ?>
    <article class="kd-page-tile"><span class="kd-page-arrow">↗</span><span class="kd-tag">Раздел сайта</span><h3><a href="<?php echo esc_url(get_permalink($p)); ?>"><?php echo esc_html(get_the_title($p)); ?></a></h3><p><?php echo esc_html(wp_trim_words(wp_strip_all_tags($p->post_content),20)); ?></p><a class="kd-text-link" href="<?php echo esc_url(get_permalink($p)); ?>">Открыть раздел →</a></article>
   <?php endforeach; else: ?>
    <article class="kd-page-tile"><h3>Разделы сайта</h3><p>Страницы появятся здесь автоматически после публикации в WordPress.</p></article>
   <?php endif; ?>
  </div>
 </div>
</section>

<section class="kd-section kd-cta-section">
 <div class="kd-wrap"><div class="kd-cta-panel"><div><span class="kd-eyebrow">Есть идея или задача?</span><h2>Давайте превратим её <span class="kd-gradient">в работающий инструмент</span></h2><p>Расскажите, что хотите создать или автоматизировать. Обсудим решение, сроки и следующий шаг.</p></div><div class="kd-cta-actions"><a class="kd-button" href="tel:+79641519362">+7 (964) 151-93-62 ↗</a><a class="kd-cta-mail" href="mailto:tochka_rosta24@list.ru">tochka_rosta24@list.ru</a></div></div></div>
</section>
</main>
<?php get_footer(); ?>