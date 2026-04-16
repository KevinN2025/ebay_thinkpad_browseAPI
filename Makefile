BINARY  := ef
SRC     := ./src
MANDIR  := /usr/local/share/man/man1

.PHONY: build run test clean install install-man db-setup

build:
	go build -o $(BINARY) $(SRC)

run: build
	./$(BINARY) $(ARGS)

test:
	go test ./...

clean:
	rm -f $(BINARY)

install: build
	sudo cp $(BINARY) /usr/local/bin/$(BINARY)

install-man:
	sudo mkdir -p $(MANDIR)
	sudo cp $(BINARY).1 $(MANDIR)/$(BINARY).1
	sudo mandb

db-setup:
ifndef DB_USER
	$(error DB_USER is not set)
endif
ifndef DB_PASS
	$(error DB_PASS is not set)
endif
ifndef DB_NAME
	$(error DB_NAME is not set)
endif
	mysql -u$(DB_USER) -p$(DB_PASS) $(DB_NAME) < db/ebayDB.sql
