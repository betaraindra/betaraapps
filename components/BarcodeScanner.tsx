import React, { useEffect, useRef, useState, useCallback } from 'react';
import { Camera, X } from 'lucide-react';

interface Props {
  onScan: (code: string) => void;
  onClose: () => void;
}

// NOTE: Since we cannot easily import large libraries like html5-qrcode in this single-file format reliably without npm,
// We will implement a simulated camera view that "captures" a frame. In a real app, use 'react-qr-reader' or 'html5-qrcode'.
// For USB scanners, they act as keyboard input, which is handled by a global listener or focused input.

const BarcodeScanner: React.FC<Props> = ({ onScan, onClose }) => {
  const videoRef = useRef<HTMLVideoElement>(null);
  const [stream, setStream] = useState<MediaStream | null>(null);
  const [error, setError] = useState<string>('');

  const startCamera = async () => {
    try {
      const mediaStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
      setStream(mediaStream);
      if (videoRef.current) {
        videoRef.current.srcObject = mediaStream;
      }
    } catch (err) {
      setError('Gagal mengakses kamera. Pastikan izin diberikan.');
    }
  };

  const stopCamera = useCallback(() => {
    if (stream) {
      stream.getTracks().forEach(track => track.stop());
      setStream(null);
    }
  }, [stream]);

  useEffect(() => {
    startCamera();
    return () => stopCamera();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const handleSimulateScan = () => {
    // In a real environment with a QR library, this would be automatic.
    // Here we simulate a successful scan of a demo product or random number for testing.
    const demoCodes = ['899123456', '899987654', '123456789'];
    const randomCode = demoCodes[Math.floor(Math.random() * demoCodes.length)];
    onScan(randomCode);
    stopCamera();
  };

  return (
    <div className="fixed inset-0 z-50 bg-black bg-opacity-90 flex flex-col items-center justify-center p-4">
      <div className="w-full max-w-md bg-white rounded-lg overflow-hidden relative">
        <div className="p-4 bg-gray-900 text-white flex justify-between items-center">
          <h3 className="font-semibold flex items-center gap-2"><Camera size={20}/> Scan Barcode</h3>
          <button onClick={() => { stopCamera(); onClose(); }}><X size={24} /></button>
        </div>
        
        <div className="relative aspect-[4/3] bg-black">
          {error ? (
            <div className="absolute inset-0 flex items-center justify-center text-white text-center p-4">
              {error}
            </div>
          ) : (
            <>
              <video ref={videoRef} autoPlay playsInline className="w-full h-full object-cover" />
              <div className="absolute inset-0 border-2 border-red-500 opacity-50 m-12 pointer-events-none"></div>
            </>
          )}
        </div>

        <div className="p-4 bg-gray-100 text-center">
           <p className="text-sm text-gray-600 mb-4">Arahkan kamera ke barcode.</p>
           {/* Simulation button for demo purposes as we don't have a real QR decoder lib linked */}
           <button 
            onClick={handleSimulateScan}
            className="w-full py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition"
           >
             Simulasi Scan Berhasil (Demo)
           </button>
        </div>
      </div>
    </div>
  );
};

export default BarcodeScanner;