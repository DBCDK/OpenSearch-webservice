#!/usr/bin/perl

# Read a log file, and report mismatches.

use warnings;
use strict;

while(<>) {
    # We check for two different cases
    # a: No title to some title - some title must match pid
    # b: title to no title - title must match pid
    # c: title to some title - title and some title must match pid. This is always some kind of error, actually...
    # Formats are trackingId, pid, stored, retreived
    # a: ERROR_MR: %ose_46_diff_getobject-60f0092e290f1-63%744000-katalog:54216653%744000-katalog:54216653 no title%54216653|870970      Det korrupte rige
    if (m/ERROR_MR: %(.*)/) {
	# Error line. Split in expressions
	print "Checking error: $1\n";
	(my $trackingId, my $pid, my $stored, my $retrieved) = split(/%/, $1);
	# print "trackingId: $trackingId\n";

	(my $foo, my $org_faust) = split(/:/, $pid); 
	# print( "org_faust: $org_faust\n");
	
	# a
	if ($stored =~ m/no title/) {
	    # Can never get no title to no title
	    if ($retrieved =~ m/no title/) {
		die "Internal error: no title => no title";
	    }
	    (my $new_faust, my $foo) = split(/\|/, $retrieved);
	    # print ("new_faust: $new_faust\n");
	    # If new_faust is different from org_faust, this is interessting error, that is not only "no title"
	    if ($org_faust != $new_faust) {
		print("TYPE A ERROR: no title => new title, but new title does not match pid: $_\n"); 		
	    }
	} else {
	    # type b and c
	    (my $stored_faust, my $foo) = split(/\|/, $stored);
	    # print ("stored_faust: $stored_faust\n");
	    
	    if ($org_faust != $stored_faust) {
		print("TYPE A/B PREV ERROR: stored title does not match pid: $_\n"); 		
	    }
	    if ($retrieved =~ m/no title/) {
		# b case
		# We have already dumped in this case, nothing more to test.
	    } else {
		# c case
		(my $new_faust, my $foo) = split(/\|/, $retrieved);
		print ("new_faust: $new_faust\n");
		# If new_faust is different from org_faust, this is interessting error, that is not only "no title"
		if ($org_faust != $new_faust) {
		    print("TYPE C ERROR: title => new title, but new title does not match pid: $_\n"); 		
		}
	    }
	    

	}


    }

}
