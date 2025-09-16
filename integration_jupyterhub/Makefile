app_name=integration_jupyterhub
cert_dir=$(HOME)/.nextcloud/certificates
get_version = $(shell  grep /version appinfo/info.xml | sed 's/.*\([0-9]\.[0-9]\.[0-9]\).*/\1/')
project_dir=$(CURDIR)
build_dir=$(CURDIR)/build/artifacts
sign_dir=$(build_dir)/sign
package_name=$(app_name)
version := $(call get_version)

all: appstore
release: appstore

docker: package
	docker run --rm --detach -p 8080:80 --name nextcloud nextcloud:latest
	sleep 5
	docker cp $(build_dir)/$(app_name)-$(version).tar.gz nextcloud:/var/www/html/custom_apps
	docker exec -u www-data nextcloud /bin/bash -c "cd /var/www/html/custom_apps && tar -xzf $(app_name)-$(version).tar.gz && rm $(app_name)-$(version).tar.gz"
	docker exec nextcloud /bin/bash -c "chown -R www-data:www-data /var/www/html/custom_apps/$(app_name)"
	docker exec -u www-data nextcloud /bin/bash -c "/var/www/html/occ maintenance:install --admin-user='admin' --admin-pass='adminpassword'"
	docker exec -u www-data nextcloud /bin/bash -c "/var/www/html/occ app:enable $(app_name)"
	docker exec -u www-data nextcloud /bin/bash -c "/var/www/html/occ app:disable firstrunwizard"
	docker exec -u www-data nextcloud /bin/bash -c "/var/www/html/occ log:manage --level 0"
	firefox -new-tab http://127.0.0.1:8080/

sign: package
	docker run --rm --volume $(cert_dir):/certificates --detach --name nextcloud nextcloud:latest
	sleep 5
	docker cp $(build_dir)/$(app_name)-$(version).tar.gz nextcloud:/var/www/html/custom_apps
	docker exec -u www-data nextcloud /bin/bash -c "cd /var/www/html/custom_apps && tar -xzf $(app_name)-$(version).tar.gz && rm $(app_name)-$(version).tar.gz"
	docker exec -u www-data nextcloud /bin/bash -c "php /var/www/html/occ integrity:sign-app --certificate /certificates/$(app_name).crt --privateKey /certificates/$(app_name).key --path /var/www/html/custom_apps/$(app_name)"
	docker exec -u www-data nextcloud /bin/bash -c "cd /var/www/html/custom_apps && tar pzcf $(app_name)-$(version).tar.gz $(app_name)"
	docker cp nextcloud:/var/www/html/custom_apps/$(app_name)-$(version).tar.gz $(build_dir)/$(app_name)-$(version).tar.gz
	sleep 3
	docker kill nextcloud
	openssl dgst -sha512 -sign $(cert_dir)/$(app_name).key $(build_dir)/$(app_name)-$(version).tar.gz | openssl base64

appstore: sign

.PHONY: composer
composer:
	composer install --prefer-dist

# Installs npm dependencies
.PHONY: npm
npm:
	npm install
	npm run build

# Same as clean but also removes dependencies installed by composer, bower and
# npm
.PHONY: distclean
distclean: clean
	rm -rf vendor/
	rm -rf node_modules/
clean:
	rm -rf $(build_dir)

package: clean npm composer
	mkdir -p $(sign_dir)
	rsync -a \
	--exclude=/build \
	--exclude=/docs \
	--exclude=/translationfiles \
	--exclude=.tx \
	--exclude=/tests \
	--exclude=.git \
	--exclude=.github \
	--exclude=/l10n/l10n.pl \
	--exclude=/CONTRIBUTING.md \
	--exclude=/issue_template.md \
	--exclude=.gitattributes \
	--exclude=.gitignore \
	--exclude=.scrutinizer.yml \
	--exclude=.travis.yml \
	--exclude=/Makefile \
	--exclude=.drone.yml \
	./ $(sign_dir)/$(app_name)
	tar -czf $(build_dir)/$(app_name)-$(version).tar.gz \
		-C $(sign_dir) $(app_name)
