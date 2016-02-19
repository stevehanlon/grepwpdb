# grepwpdb
Find and replace strings in wordpress mysql database. Handles serialized data.

## usage

```
Usage: 
  php $script [-h host] -u username [-p password] -d database -s searchstr [--sql] [--update] [--replace replacestr] [--help]

  -s: search string to find in database.
  --sql: output a sql script to stdout if --replace is given. Default is to output the matching field. (optional)
  --update: update the source database if --replace is given.  (optional)
  --replace: string to replace the pattern. If set, default is to output sql. (optional)
  --help: output this help.
```

## background

This script is one that I've used for migrating wordpress databases between domains. Plugins such as wp-clone will handle some embedded links but I've found many remnants of old URLs in the database. When clearing out the data, I've wanted to make sure that everything points to the new domain. 

The script is a more general search (and replace) which will either find any matching rows, create a SQL script to stdout to convert the rows or will directly update the database. 

The problem I found with other scripts is that serialized data in wp_postmeta or other tables wasn't handled cleanly. I've tried to improve that by recursively iterating through objects as they're found and then re-serializing them. 

