 #cp -rv . ~/wpbackup-migrator/
 rsync -avz . ~/wpbackup-migrator/
 cd ~/wpbackup-migrator/
 git add .
 git commit -m "implemented filesystem-can"
 git push
 cd -