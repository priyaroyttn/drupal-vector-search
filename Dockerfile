FROM drupal:11-php8.3-apache

# Keep a seed copy of the image codebase. The compose file bind-mounts
# ./drupal over /opt/drupal, which hides the image files on first boot.
RUN cp -a /opt/drupal /usr/src/drupal-seed

COPY docker/seed-entrypoint.sh /usr/local/bin/seed-entrypoint.sh
RUN chmod +x /usr/local/bin/seed-entrypoint.sh

ENTRYPOINT ["seed-entrypoint.sh"]
CMD ["apache2-foreground"]
