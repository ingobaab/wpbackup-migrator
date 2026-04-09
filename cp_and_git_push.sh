 #cp -rv . ~/wpbackup-migrator/
 rsync -avz . ~/wpbackup-migrator/
 cd ~/wpbackup-migrator/
 git add .
 git commit -m "first ai experiments working"
 git push
 cd -