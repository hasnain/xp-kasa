import Foundation
import Vision
import ImageIO
import CoreGraphics

let args = CommandLine.arguments
if args.count < 2 {
    fputs("usage: ocr image\n", stderr)
    exit(1)
}

let url = URL(fileURLWithPath: args[1]) as CFURL
guard let source = CGImageSourceCreateWithURL(url, nil),
      let cg = CGImageSourceCreateImageAtIndex(source, 0, nil) else {
    fputs("cannot open image\n", stderr)
    exit(2)
}

let request = VNRecognizeTextRequest()
request.recognitionLevel = .accurate
request.recognitionLanguages = ["pl-PL", "en-US"]
request.usesLanguageCorrection = false

let handler = VNImageRequestHandler(cgImage: cg, options: [:])
do {
    try handler.perform([request])
} catch {
    fputs("ocr failed: \(error)\n", stderr)
    exit(4)
}

for obs in request.results ?? [] {
    guard let text = obs.topCandidates(1).first?.string else { continue }
    let box = obs.boundingBox
    print(String(format: "%.4f\t%.4f\t%@", box.origin.y, box.origin.x, text))
}
