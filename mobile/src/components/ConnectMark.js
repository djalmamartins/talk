// Símbolo da Connect (o mesmo do logotipo em public/assets/logo.svg), desenhado em vetor.
import Svg, { Path } from 'react-native-svg';

const D =
  'M41.8,21.5C29.9,23.9,21,34.5,21,47.1c0,7,2.8,13.4,7.3,18.1L47.1,84l18.8-18.9c4.5-4.7,7.3-11.1,7.3-18.1c0-13.8-10.7-25.1-24.3-26v10.2l0,0l0,0v5.3l3.1-4.6c6.3,2,10.8,7.9,10.8,14.8c0,4.2-1.7,8-4.4,10.8l0,0L47.1,68.9L35.8,57.6l0,0c-2.7-2.8-4.4-6.6-4.4-10.8c0-8,6.1-14.6,13.9-15.5V21l0,0v-4.8L41.8,21.5L41.8,21.5z';

export function ConnectMark({ size = 40, color = '#12804A' }) {
  return (
    <Svg width={size} height={size * (76 / 68)} viewBox="13 12 68 76" accessibilityElementsHidden importantForAccessibility="no">
      <Path d={D} fill={color} />
    </Svg>
  );
}
