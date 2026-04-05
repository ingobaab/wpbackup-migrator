#scp -r . fly@arm64.baab.de:/home/fly/infoportal.arm64.baab.de/app/public/wp-content/plugins/wpbackup-migrator
rsync -avz . fly@arm64.baab.de:/home/fly/infoportal.arm64.baab.de/app/public/wp-content/plugins/wpbackup-migrator
echo "Plugin updated to https://infoportal.arm64.baab.de/wp-content/plugins/wpbackup-migrator/"
