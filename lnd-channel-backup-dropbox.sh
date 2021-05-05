#!/bin/bash
# This script listens for changes to lnd's channel backup file
# and when changes are detected, uploads the timestamped file to dropbox
# Forked from https://gist.github.com/vindard/e0cd3d41bb403a823f3b5002488e3f90
# See above link for dropbox API setup guide and systemd setup guide

# SET DROPBOX API KEY FOR UPLOADS
DROPBOX_APITOKEN="ADD_OAUTH_LONG_LIVED_TOKEN_WITH_WRITE_ACCESS_HERE"

# OPTIONAL SET A DEVICE NAME TO BE USED FOR BACKUPS (DEFAULTS TO /etc/hostname)
DEVICE=""

# INOTIFY CHECK
# --------------

install_inotify () {
	sudo apt update
	sudo apt install -y inotify-tools
}

inotifycheck () {
	dpkg -s "inotify-tools" &> /dev/null
	if [ ! $? -eq 0 ]; then
	    install_inotify
	fi
}


install_jq () {
	sudo apt update
	sudo apt install -y jq
}

jqcheck () {
	dpkg -s "jq" &> /dev/null
	if [ ! $? -eq 0 ]; then
	    install_jq
	fi
}

# SETUP
# --------------

setup_files_and_folders () {
	# Fetches the user whose home folder the directories will be stored under
	ADMINUSER=( $(ls /home | grep -v bitcoin) )

	if [ -z "$DEVICE" ] ; then
		DEVICE=$(echo $(cat /etc/hostname))
	fi
	DEVICE=$(echo $DEVICE | awk '{print tolower($0)}' | sed -e 's/ /-/g')

	# Setup folders and filenames
	DATADIR=/home/$ADMINUSER/.lnd
	WORKINGDIR=/home/$ADMINUSER/.lnd/channel-backups
	BACKUPFOLDER=$DEVICE

	# channel.backup file details
	CHANFILEDIR=data/chain/bitcoin/mainnet
	BACKUPFILE=channel.backup
	SOURCEFILE=$DATADIR/$CHANFILEDIR/$BACKUPFILE

	# Make sure necessary folders exist
	if [[ ! -e ${WORKINGDIR} ]]; then
	        mkdir -p ${WORKINGDIR}
	fi
	cd ${WORKINGDIR}

	if [[ ! -e ${BACKUPFOLDER} ]]; then
	        mkdir -p ${BACKUPFOLDER}
	fi
	cd ${BACKUPFOLDER}
}


# CHECKS
# --------------

online_check () {
	wget -q --tries=10 --timeout=20 --spider http://google.com
	if [[ $? -eq 0 ]]; then
	        ONLINE=true
	else
	        ONLINE=false
	fi
	#echo "Online: "$ONLINE
}

dropbox_api_check () {
	VALID_DROPBOX_APITOKEN=false
	curl -s -X POST https://api.dropboxapi.com/2/users/get_current_account \
	    --header "Authorization: Bearer "$DROPBOX_APITOKEN | grep rror
	if [[ ! $? -eq 0 ]] ; then
	        VALID_DROPBOX_APITOKEN=true
	else
		echo "Invalid Dropbox API Token!"
	fi
}

dropbox_upload_check () {
	UPLOAD_TO_DROPBOX=false
	if [ ! -z $DROPBOX_APITOKEN ] ; then
		online_check
		if [ $ONLINE = true ] ; then
			dropbox_api_check
		else
			echo "Please check that the internet is connected and try again."
		fi

		if [ $VALID_DROPBOX_APITOKEN = true ] ; then
			UPLOAD_TO_DROPBOX=true
		fi
	fi
}


# UPLOAD
# --------------

upload_to_dropbox () {
	FINISH=$(curl -s -X POST https://content.dropboxapi.com/2/files/upload \
	    --header "Authorization: Bearer "${DROPBOX_APITOKEN}"" \
	    --header "Dropbox-API-Arg: {\"path\": \"/"$BACKUPFOLDER"/"$1"\",\"mode\": \"overwrite\",\"autorename\": true,\"mute\": false,\"strict_conflict\": false}" \
	    --header "Content-Type: application/octet-stream" \
	    --data-binary @$1)
	#echo $FINISH 
	UPLOADTIME=$(echo $FINISH | jq -r .server_modified)
	if [ ! -z $UPLOADTIME ] ; then
		echo "Successfully uploaded!"
	else
		echo "Unknown error when uploading..."
	fi
}

# RUN CHECKS AND IF PASS, EXECUTE BACKUP TO DROPBOX
run_dropbox_backup () {
	dropbox_upload_check
	if [ $UPLOAD_TO_DROPBOX = true ] ; then
		upload_to_dropbox $1
	fi
}


##############
# RUN SCRIPT
##############

run_backup_on_change () {
	    echo "Copying backup file..."
	    BACKUPFILE_TIMESTAMPED=$BACKUPFILE-$(date +%s)
	    cp $SOURCEFILE $BACKUPFILE_TIMESTAMPED
	    md5sum $SOURCEFILE > $BACKUPFILE_TIMESTAMPED.md5
	    sed -i 's/\/.*\///g' $BACKUPFILE_TIMESTAMPED.md5

	    echo
	    echo "Uploading backup file: '"${BACKUPFILE_TIMESTAMPED}"'..."
	    run_dropbox_backup $BACKUPFILE_TIMESTAMPED
	    echo "---"
	    echo "Uploading signature: '"${BACKUPFILE_TIMESTAMPED}.md5"'..."
	    run_dropbox_backup $BACKUPFILE_TIMESTAMPED.md5
}

run () {
	inotifycheck
	jqcheck
	setup_files_and_folders
	run_backup_on_change
	while true; do
	    inotifywait $SOURCEFILE
	    run_backup_on_change
	    echo
	done
}

run

