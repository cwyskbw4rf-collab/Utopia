CC      ?= gcc
CFLAGS  ?= -O2 -Wall -Wextra -pthread
TARGET  := httpbench

all: $(TARGET)

$(TARGET): httpbench.c
	$(CC) $(CFLAGS) -o $@ $<

clean:
	rm -f $(TARGET)

.PHONY: all clean
