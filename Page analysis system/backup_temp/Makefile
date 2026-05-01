# Makefile لنظام تحليل الصفحات

.PHONY: help install setup test clean docker-up docker-down migrate logs cache-clear

# المساعدة
help:
	@echo "الأوامر المتاحة:"
	@echo "  install     - تثبيت dependencies"
	@echo "  setup       - إعداد النظام لأول مرة"
	@echo "  test        - تشغيل جميع الاختبارات"
	@echo "  migrate     - تشغيل database migrations"
	@echo "  docker-up   - تشغيل Docker environment"
	@echo "  docker-down - إيقاف Docker environment"
	@echo "  logs        - عرض السجلات"
	@echo "  cache-clear - تنظيف cache"
	@echo "  clean       - تنظيف الملفات المؤقتة"

# تثبيت dependencies
install:
	npm install
	@echo "Dependencies installed"

# إعداد النظام
setup: install
	cp .env.example .env
	mkdir -p logs cache
	chmod 755 logs cache
	@echo "System setup completed. Edit .env file with your settings."

# تشغيل الاختبارات
test:
	@echo "Testing cache system..."
	curl -s http://localhost/api/test-cache.php || echo "Cache test failed"
	@echo "Testing logging system..."
	curl -s http://localhost/api/test-log.php || echo "Log test failed"
	@echo "Testing rate limiting..."
	curl -s http://localhost/api/test-rate-limit.php || echo "Rate limit test failed"
	@echo "All tests completed"

# تشغيل migrations
migrate:
	cd api && php migrate.php

# Docker commands
docker-up:
	docker-compose up -d
	@echo "Docker environment started"

docker-down:
	docker-compose down
	@echo "Docker environment stopped"

# عرض السجلات
logs:
	tail -f logs/app.log

# تنظيف cache
cache-clear:
	rm -rf cache/*
	@echo "Cache cleared"

# تنظيف الملفات المؤقتة
clean: cache-clear
	rm -rf node_modules
	rm -f logs/*.log
	@echo "Cleanup completed"