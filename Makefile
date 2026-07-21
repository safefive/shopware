SHELL := bash
.ONESHELL:
.SHELLFLAGS := -eu -o pipefail -c
.DELETE_ON_ERROR:
MAKEFLAGS += --warn-undefined-variables
MAKEFLAGS += --no-builtin-rules

ifeq ($(origin ENV_FILE), undefined)
    ENV_FILE:=.env
endif

-include $(ENV_FILE)

.PHONY: init clear-cache check-code prepare-phpstan test-mink test-mink-from-ci-db prepare-mink-after-restore elasticsearch-populate test-phpunit test-phpunit-no-init test-phpunit-coverage-cobertura test-phpunit-coverage-statistic test-phpunit-elasticsearch test-phpunit-elasticsearch-no-init restore-ci-db debug-config clean-make-config clean check-config-variables

init: .make.init

clean-setup:
	bash ./var/cache/clear_cache.sh
	rm -rf web/cache/*.js
	rm -rf web/cache/*.css
	rm -rf web/sitemap/shop*

clear-cache: .make.console.executable
	./bin/console sw:cache:clear

check-code: prepare-phpstan check-phpstan check-php-cs-fixer
check-js-code: check-eslint-frontend check-eslint-backend

prepare-phpstan: .make.install.composer-dependencies .make.config .make.console.executable
	./bin/console sw:generate:attributes

check-phpstan: prepare-phpstan
	./vendor/bin/phpstan analyze -c .phpstan.neon --memory-limit=4G

check-php-cs-fixer: .make.install.composer-dependencies
	composer bin all install
	./vendor/bin/php-cs-fixer fix --dry-run -v
	./vendor/bin/php-cs-fixer fix --dry-run -v --config engine/Library/Enlight/.php-cs-fixer.php

fix-code-style: .make.install.composer-dependencies
	composer bin all install
	php -d memory_limit=-1 ./vendor/bin/php-cs-fixer fix -v
	php -d memory_limit=-1 ./vendor/bin/php-cs-fixer fix -v --config engine/Library/Enlight/.php-cs-fixer.php

generate-phpstan-baseline: prepare-phpstan
	mv .phpstan-baseline.neon phpstan-baseline.neon
	sed -i -r 's/- .phpstan-baseline.neon/- phpstan-baseline.neon/g' .phpstan.neon
	./vendor/bin/phpstan analyze -c .phpstan.neon --memory-limit=4G --generate-baseline
	wait;
	mv phpstan-baseline.neon .phpstan-baseline.neon
	sed -i -r 's/- phpstan-baseline.neon/- .phpstan-baseline.neon/g' .phpstan.neon

check-eslint-frontend: .make.install.npm-dependencies
	npm run lint --prefix ./themes

fix-eslint-frontend: .make.install.npm-dependencies
	npm run fix --prefix ./themes

check-eslint-backend: .make.install.npm-dependencies
	npm run lintBackend --prefix ./themes

fix-eslint-backend: .make.install.npm-dependencies
	npm run fixBackend --prefix ./themes

frontend-watch: clear-cache .make.install.npm-dependencies
	./bin/console sw:theme:dump:configuration
	npm run watch --prefix ./themes

prepare-mink: .make.config.build.mink
	./bin/console sw:rebuild:seo:index
	./bin/console sw:theme:cache:generate
	./bin/console sw:config:set show_cookie_note false -d
	./bin/console sw:cache:clear

# Post-import steps for a fresh `make init` (empty DB after importDemodata).
.post-db-install: .make.config .make.console.executable
	./bin/console sw:cache:clear
	./bin/console sw:database:setup --steps=setupShop --shop-url=http://$(SW_HOST)$(SW_BASE_PATH)
	./bin/console sw:snippets:to:db --include-plugins
	./bin/console sw:theme:initialize
	./bin/console sw:firstrunwizard:disable
	./bin/console sw:admin:create --name="Demo" --email="demo@demo.de" --username="demo" --password="demo" --locale=de_DE -n

# Post-restore steps for a ci-cache dump (already contains demo user and shop data).
.post-db-install-after-restore: .make.config .make.console.executable
	./bin/console sw:cache:clear
	./bin/console sw:database:setup --steps=setupShop --shop-url=http://$(SW_HOST)$(SW_BASE_PATH)
	./bin/console sw:snippets:to:db --include-plugins
	./bin/console sw:theme:initialize
	./bin/console sw:firstrunwizard:disable

prepare-mink-after-restore: .post-db-install-after-restore prepare-mink

test-mink: init .make.config.build.mink
	./vendor/bin/behat -vv --config=tests/Mink/behat.yml --format=pretty --out=std --format=junit --out=build/artifacts \
		|| ./vendor/bin/behat --rerun -vv --config=tests/Mink/behat.yml --format=pretty --out=std --format=junit --out=build/artifacts

# Same flow as the GitLab Mink job (requires ci-cache/ dump and a running Selenium on SELENIUM_HOST:4444).
test-mink-from-ci-db: restore-ci-db prepare-mink-after-restore
	mkdir -p build/artifacts/mink
	./vendor/bin/behat -vv --config=tests/Mink/behat.yml --format=pretty --out=std --format=junit --out=build/artifacts/mink \
		|| ./vendor/bin/behat --rerun -vv --config=tests/Mink/behat.yml --format=pretty --out=std --format=junit --out=build/artifacts/mink

test-phpunit: init
	./vendor/bin/phpunit --config tests/phpunit_unit.xml.dist --log-junit build/artifacts/test-log.xml
	./vendor/bin/phpunit --config tests/phpunit.xml.dist --log-junit build/artifacts/test-log.xml --exclude-group=elasticSearch
	./vendor/bin/phpunit --config recovery/common/phpunit.xml.dist --log-junit build/artifacts/test-log.xml

test-phpunit-no-init: .make.console.executable
	mkdir -p build/artifacts
	./vendor/bin/phpunit --config tests/phpunit_unit.xml.dist --log-junit build/artifacts/test-log.xml
	./vendor/bin/phpunit --config tests/phpunit.xml.dist --log-junit build/artifacts/test-log.xml --exclude-group=elasticSearch
	./vendor/bin/phpunit --config recovery/common/phpunit.xml.dist --log-junit build/artifacts/test-log.xml

restore-ci-db:
	bash .gitlab/scripts/ci-db-cache-restore.sh

test-phpunit-coverage-cobertura: init
	php -d pcov.enabled=1 -d pcov.directory="$(CURDIR)" vendor/bin/phpunit --configuration="tests/phpunit.xml.dist" --colors="never" --exclude-group="elasticSearch,pcovAdapterBrokenTest" --testsuite="$(TESTSUITE)" --coverage-cobertura="build/artifacts/phpunit-coverage-$(TESTSUITE).cobertura.xml"

test-phpunit-coverage-cobertura-no-init: .make.console.executable tests/phpunit-full-coverage.xml
	mkdir -p build/artifacts
	php -d pcov.enabled=1 -d pcov.directory="$(CURDIR)" vendor/bin/phpunit --configuration="tests/phpunit.xml.dist" --colors="never" --exclude-group="elasticSearch,pcovAdapterBrokenTest" --testsuite="$(TESTSUITE)" --coverage-cobertura="build/artifacts/phpunit-coverage-$(TESTSUITE).cobertura.xml"

test-phpunit-coverage-statistic: init tests/phpunit-full-coverage.xml
	php -d pcov.enabled=1 -d pcov.directory="$(CURDIR)" vendor/bin/phpunit --configuration="tests/phpunit-full-coverage.xml" --colors="never" --exclude-group="elasticSearch,pcovAdapterBrokenTest" --testsuite="$(TESTSUITE)" --coverage-text

test-phpunit-coverage-statistic-no-init: .make.console.executable tests/phpunit-full-coverage.xml
	php -d pcov.enabled=1 -d pcov.directory="$(CURDIR)" vendor/bin/phpunit --configuration="tests/phpunit-full-coverage.xml" --colors="never" --exclude-group="elasticSearch,pcovAdapterBrokenTest" --testsuite="$(TESTSUITE)" --coverage-text

test-phpunit-elasticsearch: elasticsearch-populate
	./vendor/bin/phpunit --config tests/phpunit.xml.dist --log-junit build/artifacts/test-log.xml --exclude-group=skipElasticSearch --group=elasticSearch

test-phpunit-elasticsearch-no-init: .make.config.build.elasticsearch .make.console.executable
	mkdir -p build/artifacts
	./bin/console sw:es:index:populate
	./bin/console sw:es:backend:index:populate
	./vendor/bin/phpunit --config tests/phpunit.xml.dist --log-junit build/artifacts/test-log.xml --exclude-group=skipElasticSearch --group=elasticSearch

test-jest: .make.install.npm-dependencies
	npm run test --prefix ./themes/Frontend/Responsive

tests/phpunit-full-coverage.xml:
	sed -e 's/includeUncoveredFiles="false"/includeUncoveredFiles="true"/g' < tests/phpunit.xml.dist > tests/phpunit-full-coverage.xml

elasticsearch-populate: .make.config.build.elasticsearch .make.console.executable
	./bin/console sw:es:index:populate
	./bin/console sw:es:backend:index:populate

debug-config: clean-make-config .make.config.build.debug
	@echo "Debug configuration file generated"

config: clean-make-config .make.config
	@echo "Default configuration file generated"

check-config-variables:
	@if [ -z "$(DB_USER)" ]; then echo "No or invalid database user supplied"; exit 1; fi
	@if [ -z "$(DB_PORT)" ]; then echo "No or invalid database port supplied"; exit 1; fi
	@if [ -z "$(DB_NAME)" ]; then echo "No or invalid database name supplied"; exit 1; fi
	@if [ -z "$(DB_HOST)" ]; then echo "No or invalid database host supplied"; exit 1; fi
	@if [ -z "$(DB_PASSWORD)" ]; then echo "No or invalid database password supplied"; exit 1; fi

clean-make-config:
	rm -f .make.config.*
	rm -f .make.install.*

clean:
	rm .make.* 2> /dev/null || true

debug-config-test: .make.config.build.debug

.make.config: check-config-variables .make.config.behat
	@sed -e 's/%db\.user%/$(DB_USER)/g' -e 's/%db\.password%/$(DB_PASSWORD)/g' -e 's/%db\.database%/$(DB_NAME)/g' -e 's/%db\.host%/$(DB_HOST)/g' -e 's/%db\.port%/$(DB_PORT)/g' -e 's/%db\.port%/$(DB_PORT)/g' -e 's/%elasticsearch\.host%/$(ELASTICSEARCH_HOST)/g' -e 's|%smtp\.host%|$(SMTP_HOST)|g' < ./config.php.dist > ./config.php
	touch $@

.make.config.build.%: check-config-variables .make.config.behat
	@sed -e 's/%db\.user%/$(DB_USER)/g' -e 's/%db\.password%/$(DB_PASSWORD)/g' -e 's/%db\.database%/$(DB_NAME)/g' -e 's/%db\.host%/$(DB_HOST)/g' -e 's/%db\.port%/$(DB_PORT)/g' -e 's/%db\.port%/$(DB_PORT)/g' -e 's/%elasticsearch\.host%/$(ELASTICSEARCH_HOST)/g' -e 's|%smtp\.host%|$(SMTP_HOST)|g' < ./build/config-$*.php > ./config.php
	touch $@

.make.config.behat:
	@sed -e 's/%sw\.host%/$(SW_HOST)/g' -e 's|%sw\.path%|$(SW_BASE_PATH)|g' -e 's|%selenium\.host%|$(SELENIUM_HOST)|g' < ./tests/Mink/behat.yml.dist > ./tests/Mink/behat.yml
	touch $@

.make.install.composer-dependencies:
	composer update laminas/laminas-code
	composer install
	composer install -d recovery/common
	composer bin all install
	cp .htaccess.dist .htaccess
	touch $@

.make.install.npm-dependencies:
	npm install --prefix ./themes
	npm install --prefix ./themes/Frontend/Responsive
	touch $@

.make.install: .make.install.composer-dependencies
	@echo "Read additional variables from $(ENV_FILE)"
	./bin/console sw:database:setup --steps=drop,create,import,importDemodata
	$(MAKE) .post-db-install

recovery/install/data/install.lock:
	touch $@

.git/hooks/pre-commit:
	mkdir -p .git/hooks
	ln -s ../../build/gitHooks/pre-commit $@

.make.init: clean-make-config .make.config .make.install recovery/install/data/install.lock .git/hooks/pre-commit

.make.console.executable:
	chmod u+x bin/console
	touch $@
