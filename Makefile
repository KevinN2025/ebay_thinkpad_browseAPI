BINARY  := ebay_find
SRC     := ./src

.PHONY: build run test clean db-setup

build:
	go build -o $(BINARY) $(SRC)

run: build
	./$(BINARY) $(ARGS)

test:
	go test ./...

clean:
	rm -f $(BINARY)

db-setup:
	mysql -u$(DB_USER) -p$(DB_PASS) $(DB_NAME) < db/ebayDB.sql
