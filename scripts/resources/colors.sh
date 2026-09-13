#!/bin/bash

# Detect whether the current terminal understands color codes. Redirected logs
# stay readable because color is disabled when no interactive terminal exists.
if test -t 1; then
    ncolors=$(tput colors)
    if test -n "$ncolors" && test "$ncolors" -ge 8; then
        normal="$(tput sgr0)"
        red="$(tput setaf 1)"
        green="$(tput setaf 2)"
        yellow="$(tput setaf 3)"
    fi
fi

# Print a green informational message
verbose () {
    echo "${green}$1${normal}"
}

# Print a red error message
error () {
    echo "${red}$1${normal}"
}

# Print a yellow warning message
warning () {
    echo "${yellow}$1${normal}"
}
