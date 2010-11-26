# ============================================================================
#			Commands
# ============================================================================

CC=php -l
UNITCC=phpunit

DOXY_TOOL=doxygen

# ============================================================================
#			Objects
# ============================================================================

SOURCES=$(patsubst %.php,%.chk,$(wildcard *.php))

UNIT_FILE=AllTests.php

DOXY_PATH=doc
DOXY_FILE=opensearch.doxygen

# ============================================================================
#			Targets
# ============================================================================

all: compile test

compile: $(SOURCES)
	$(MAKE) -C OLS_class_lib compile

%.chk: %.php
	$(CC) $<

test:
	$(UNITCC) $(UNIT_FILE)
	$(MAKE) -C OLS_class_lib test

api:
	cd $(DOXY_PATH) ; $(DOXY_TOOL) $(DOXY_FILE)

