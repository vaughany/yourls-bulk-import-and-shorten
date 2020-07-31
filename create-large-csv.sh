#!/bin/bash

# Each 'run' generates 10 unique URLs, so if RUNS=10 you'll get 100 lines out.
# Uses the command `gpw` to create random-ish data. This is not installed by default: `sudo apt-get install gpw`

TIME_START=$(date +%s)
FILE=test-large.csv
RUNS=1000

if test -f "$FILE"; then
    truncate -s 0 $FILE
else
    touch $FILE
fi

for (( i=1; i <= $RUNS; i++)); do
    string=$(gpw 1 25)
    echo "http://$string.co.uk" >> $FILE
    echo "http://$string.net" >> $FILE
    echo "http://$string.org" >> $FILE
    echo "http://$string.org.uk" >> $FILE
    echo "http://$string.eu" >> $FILE
    echo "http://$string.ie" >> $FILE
    echo "http://$string.irish" >> $FILE
    echo "http://$string.cymru" >> $FILE
    echo "http://$string.wales" >> $FILE
    echo "http://$string.scot" >> $FILE

    if [[ $(( $i % 100 )) -eq "0" ]]; then
        echo "Done $(wc -l $FILE | cut -d " " -f 1) lines."
    fi
done

TIME_TAKEN=$(( ($(date +%s) - $TIME_START) ))

echo
echo "$(wc -l $FILE | cut -d " " -f 1) lines."

echo
ls -gh $FILE

echo
echo "Took ${TIME_TAKEN}s."
