.PHONY: install

INSTALL = install

prefix = $(HOME)
bindir_relative = bin
bindir = $(prefix)/$(bindir_relative)

bindir_SQ = $(subst ','\'',$(bindir))

install:
	$(INSTALL) -T -m 755 phptags.php '$(bindir_SQ)/phptags'

