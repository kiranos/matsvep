INSERT INTO engine_config (`key`, value, type, description, version) VALUES
    ('crawl.discovery_max_age_days', '7', 'integer', 'Rediscover the live sitemap after this many days', 1),
    ('crawl.batch_size', '50', 'integer', 'Maximum URLs per timed crawler fetch batch', 1),
    ('crawl.max_retry_wait_s', '300', 'integer', 'Maximum wait for a scheduled retry in one crawler run', 1);
