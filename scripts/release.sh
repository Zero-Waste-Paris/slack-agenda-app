#!/bin/bash
set -e

# Find out directory of current script
# to make it possible to run this script from any location
if [ -L "$0" ] && [ -x $(which readlink) ]; then
  THIS_FILE="$(readlink -mn "$0")"
else
  THIS_FILE="$0"
fi

SCRIPT_DIR="$(realpath "$(dirname "$THIS_FILE")")"
ROOT_DIR="$SCRIPT_DIR/.."
TEMPORARY_RELEASE_DIR="$ROOT_DIR/temp_for_release"

# parse arguments
TARGET_ENVIRONMENT=preprod # default value; overridable by cli options
while [[ $# -gt 0 ]]; do
  case $1 in
    --env)
      TARGET_ENVIRONMENT="$2"
      shift # past argument
      shift # past value
      ;;
    -h|--help)
      echo "Options:"
      echo " --env"
      echo "   either 'prod' or 'preprod' (or a custom value if you defined such config). Default to 'preprod'"
      echo "   E.g.: $0 --env prod"
      echo " -h|--help: displays this help and exits"
      exit 0
      ;;
   *)
     echo "Unknown option $1"
     exit 1
     ;;
 esac
done
echo "Going to deploy to environment: $TARGET_ENVIRONMENT"

CONF_DIR="$SCRIPT_DIR"/"$TARGET_ENVIRONMENT-config"
LOCAL_CONF_FILE="$CONF_DIR"/deploy-config.sh


# Exit if some files which should have been added during setup are missing
# Here we don't juste warn, we exit, because missing files will most certainly lead
# to breaking the prod or to add security holes
function testFileOrDie {
  if [ ! -f "$1" ]; then
    echo File "$1" is needed but it missing. Setup may not be completed. See README for more info
    exit 1
  fi
}
testFileOrDie "$CONF_DIR/config.json"
testFileOrDie "$LOCAL_CONF_FILE"

# Load conf
source "$LOCAL_CONF_FILE"

# Warn if the repo isn't clean , to prevent releasing corrupted code
if ! [ -z "$(git status --porcelain)" ]; then
  git status
  read -p "Repo not clean. Are you sure you want to deploy? [yN]" ANSWER
  if [ "x$ANSWER" != "xy" ]; then
    echo aborting
    exit 1
  fi
fi

# Run the tests
# TODO

# Copy the files to a temporary directory
rm -rf "$TEMPORARY_RELEASE_DIR"
mkdir -p "$TEMPORARY_RELEASE_DIR"
pushd $ROOT_DIR
cp -rL inc .htaccess *.php app_logo.png clitools composer* "$TEMPORARY_RELEASE_DIR"
popd

pushd "$TEMPORARY_RELEASE_DIR"
cp "$CONF_DIR"/config.json "$TEMPORARY_RELEASE_DIR"
composer install --optimize-autoloader --no-dev
popd

# Releasing
echo "All good, we're going to perform the release to $TARGET_ENVIRONMENT"
rsync -avz --delete "$TEMPORARY_RELEASE_DIR/" "$RSYNC_DESTINATION"

echo DONE

