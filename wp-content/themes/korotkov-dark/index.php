<?php get_header(); ?>
<main class="kd-main kd-blog" id="content">
  <section class="kd-blog-hero">
    <div class="kd-wrap">
      <span class="kd-eyebrow">Заметки разработчика · Практика · Разборы</span>
      <h1>Блог <span class="kd-gradient">о разработке и автоматизации</span></h1>
      <p class="kd-blog-intro">Пишу о создании сайтов, WordPress, парсинге данных и инструментах, которые помогают решать реальные рабочие задачи. Без сложных терминов там, где можно объяснить проще.</p>
      <div class="kd-blog-topline"><span><?php echo esc_html(wp_count_posts()->publish); ?> публикаций</span><span>Опыт, решения и полезные наблюдения</span></div>
    </div>
  </section>
  <section class="kd-blog-list">
    <div class="kd-wrap">
      <div class="kd-blog-sectionhead"><div><span class="kd-eyebrow">База знаний</span><h2>Последние материалы</h2></div></div>
      <?php if(have_posts()): ?>
        <div class="kd-blog-grid">
          <?php while(have_posts()): the_post(); ?>
            <article <?php post_class('kd-blog-item'); ?>>
              <?php if(has_post_thumbnail()): ?><a class="kd-blog-thumb" href="<?php the_permalink(); ?>"><?php the_post_thumbnail('large'); ?></a><?php endif; ?>
              <div class="kd-blog-itembody">
                <div class="kd-meta"><?php echo esc_html(get_the_date('d.m.Y')); ?><?php $cats=get_the_category(); if($cats): ?><span class="kd-blog-dot">/</span><?php echo esc_html($cats[0]->name); ?><?php endif; ?></div>
                <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
                <p><?php echo esc_html(wp_trim_words(get_the_excerpt(), 26, '…')); ?></p>
                <a class="kd-blog-read" href="<?php the_permalink(); ?>">Читать материал <span aria-hidden="true">↗</span></a>
              </div>
            </article>
          <?php endwhile; ?>
        </div>
        <nav class="kd-blog-pagination" aria-label="Навигация по публикациям"><?php the_posts_pagination(array('mid_size'=>1,'prev_text'=>'← Назад','next_text'=>'Дальше →')); ?></nav>
      <?php else: ?>
        <div class="kd-blog-empty"><h3>Материалы готовятся</h3><p>Скоро здесь появятся статьи о разработке сайтов, WordPress и автоматизации.</p></div>
      <?php endif; ?>
    </div>
  </section>
  <section class="kd-blog-bottom"><div class="kd-wrap"><div><span class="kd-eyebrow">Есть задача?</span><h2>Нужна помощь с сайтом или данными?</h2><p>Обсудим задачу и подберём понятный вариант решения.</p></div><a class="kd-blog-cta" href="<?php echo esc_url(home_url('/contacts/')); ?>">Обсудить проект <span aria-hidden="true">↗</span></a></div></section>
</main>
<?php get_footer(); ?>