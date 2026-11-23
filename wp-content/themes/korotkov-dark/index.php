<?php get_header(); ?>
<main class="kd-main kd-blog" id="content">
  <section class="kd-blog-hero">
    <div class="kd-wrap">
      <span class="kd-eyebrow">Заметки разработчика</span>
      <h1><?php echo is_home() ? 'Блог' : 'Публикации'; ?><br><span class="kd-gradient">о разработке и автоматизации</span></h1>
      <p class="kd-blog-lead">Практические материалы о WordPress, создании сайтов, парсинге данных и автоматизации. Разбираю рабочие подходы, инструменты и типичные ошибки — без лишней теории.</p>
      <div class="kd-blog-topics">
        <span>WordPress</span><span>Разработка сайтов</span><span>Парсеры</span><span>Автоматизация</span>
      </div>
    </div>
  </section>
  <section class="kd-blog-list">
    <div class="kd-wrap">
      <?php if(have_posts()): ?>
        <div class="kd-blog-grid">
          <?php $kd_post_index = 0; while(have_posts()): the_post(); $kd_post_index++; ?>
            <article <?php post_class('kd-blog-card' . ($kd_post_index === 1 ? ' kd-blog-card-featured' : '')); ?>>
              <div class="kd-blog-card-top">
                <span class="kd-blog-date"><?php echo esc_html(get_the_date('d.m.Y')); ?></span>
                <span class="kd-blog-category"><?php the_category(' · '); ?></span>
              </div>
              <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
              <p class="kd-blog-excerpt"><?php echo esc_html(wp_trim_words(get_the_excerpt(), 34, '…')); ?></p>
              <a class="kd-blog-read" href="<?php the_permalink(); ?>">Читать материал <span aria-hidden="true">→</span></a>
            </article>
          <?php endwhile; ?>
        </div>
        <div class="kd-pagination"><?php the_posts_pagination(array('mid_size'=>1,'prev_text'=>'← Назад','next_text'=>'Дальше →')); ?></div>
      <?php else: ?>
        <div class="kd-blog-empty"><span class="kd-eyebrow">Скоро здесь появятся материалы</span><h2>Готовлю практические статьи</h2><p>В блоге будут разборы разработки сайтов, WordPress, парсеров и автоматизации рабочих процессов.</p></div>
      <?php endif; ?>
    </div>
  </section>
</main>
<?php get_footer(); ?>
