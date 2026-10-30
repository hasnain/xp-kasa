import sys
import pymupdf

src, dst = sys.argv[1], sys.argv[2]
doc = pymupdf.open(src)
page = doc[0]
pix = page.get_pixmap(matrix=pymupdf.Matrix(2, 2), alpha=False)
pix.save(dst)
