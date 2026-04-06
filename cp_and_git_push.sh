 #cp -rv . ~/wpbackup-migrator/
 rsync -avz . ~/wpbackup-migrator/
 cd ~/wpbackup-migrator/
 git add .
 git commit -m "added databse table in info"
 git push
 cd -